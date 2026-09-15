<?php
/**
 * Local anti-spam pipeline integration tests (Phase 4).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Entries\Entry;
use SentinelForms\Entries\EntryRepository;
use SentinelForms\Events\EventRecorder;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormSchema;
use SentinelForms\Http\SubmissionHandler;
use SentinelForms\Tests\Integration\Support\RedirectedException;
use SentinelForms\Tests\Integration\Support\StoppedException;

/**
 * Signals → score → status → notification policy on real WordPress.
 *
 * @group integration
 */
final class SpamFlowTest extends TestCase {

	/**
	 * A normal human-speed submission stays legitimate and emails.
	 */
	public function testLegitimateSubmissionIsScoredAndEmailed(): void {
		$form = $this->makeForm();

		$emails = [];
		add_filter(
			'pre_wp_mail',
			static function ( ?bool $shortCircuit, array $args ) use ( &$emails ): bool {
				$emails[] = $args;

				return true;
			},
			10,
			2
		);

		$url = $this->submit(
			$form->id,
			[
				'sentinel_field_f_name'  => 'Ada',
				'sentinel_field_f_email' => 'ada@example.com',
				'sentinel_field_f_msg'   => 'Ordinary enquiry.',
			],
			microtime( true ) - 30.0
		);

		$this->assertStringContainsString( 'sentinel_form_success=', $url['url'] ?? '' );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertCount( 1, $entries );

		$entry = $entries[0];
		$this->assertSame( Entry::STATUS_NEW, $entry->status );

		$signals = $entry->meta['_spam_signals'] ?? [];
		$this->assertSame( 0.0, (float) ( $signals['spam_score'] ?? 1.0 ) );
		$this->assertSame( 0, (int) ( $signals['links_detected'] ?? -1 ) );

		$this->assertCount( 1, $emails );

		$events = EventRecorder::forEntry( (int) $entry->id );
		$this->assertContains( 'spam_scored', array_column( $events, 'event' ) );
	}

	/**
	 * An instant submission full of links is flagged suspicious.
	 */
	public function testFastLinkHeavySubmissionIsSuspicious(): void {
		$form = $this->makeForm();

		$this->submit(
			$form->id,
			[
				'sentinel_field_f_name'  => 'Bot',
				'sentinel_field_f_email' => 'bot@example.com',
				'sentinel_field_f_msg'   => 'https://a.example https://b.example https://c.example https://d.example',
			],
			microtime( true ) - 0.01
		);

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertCount( 1, $entries );

		$entry = $entries[0];
		$this->assertSame( Entry::STATUS_SUSPICIOUS, $entry->status );

		$signals = $entry->meta['_spam_signals'] ?? [];
		$this->assertSame( 4, (int) ( $signals['links_detected'] ?? 0 ) );
		$this->assertGreaterThanOrEqual( 0.5, (float) ( $signals['spam_score'] ?? 0.0 ) );
	}

	/**
	 * Duplicates within the window raise the score.
	 */
	public function testDuplicateSubmissionsAreDetected(): void {
		$form = $this->makeForm();

		$this->submit( $form->id, $this->fields(), microtime( true ) - 30.0 );
		$this->submit( $form->id, $this->fields(), microtime( true ) - 30.0 );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertCount( 2, $entries );

		// Entries share a second-resolution timestamp; newest = highest id.
		usort( $entries, static fn ( Entry $a, Entry $b ): int => (int) $b->id <=> (int) $a->id );

		$second  = $entries[0];
		$signals = $second->meta['_spam_signals'] ?? [];
		$this->assertSame( 1, (int) ( $signals['duplicate_count'] ?? 0 ) );
	}

	/**
	 * Exceeding the rate limit plus a duplicate lands in spam and is not emailed.
	 */
	public function testRateLimitedDuplicateIsSpamWithoutEmail(): void {
		add_filter( 'sentinel_forms_rate_limit', static fn (): int => 1 );

		$form = $this->makeForm();

		$emails = [];
		add_filter(
			'pre_wp_mail',
			static function ( ?bool $shortCircuit, array $args ) use ( &$emails ): bool {
				$emails[] = $args;

				return true;
			},
			10,
			2
		);

		$this->submit( $form->id, $this->fields(), microtime( true ) - 30.0 );
		$this->submit( $form->id, $this->fields(), microtime( true ) - 30.0 );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertCount( 2, $entries );

		usort( $entries, static fn ( Entry $a, Entry $b ): int => (int) $b->id <=> (int) $a->id );

		$spam = $entries[0];
		$this->assertSame( Entry::STATUS_SPAM, $spam->status );

		$signals = $spam->meta['_spam_signals'] ?? [];
		$this->assertGreaterThanOrEqual( 0.95, (float) ( $signals['spam_score'] ?? 0.0 ) );

		// Spam never emails (doc §20).
		$this->assertCount( 1, $emails );

		$events = EventRecorder::forEntry( (int) $spam->id );
		$names  = array_column( $events, 'event' );
		$this->assertContains( 'notification_skipped', $names );

		// Spam inbox filter surfaces it.
		$spamInbox = ( new EntryRepository() )->findMany(
			[
				'form_id' => $form->id,
				'status'  => Entry::STATUS_SPAM,
			]
		);
		$this->assertCount( 1, $spamInbox );
	}

	/**
	 * Build a published three-field form.
	 */
	private function makeForm(): \SentinelForms\Forms\Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Anti spam' )->withFields(
			[
				[
					'id'       => 'f_name',
					'type'     => 'text',
					'label'    => 'Name',
					'required' => true,
				],
				[
					'id'       => 'f_email',
					'type'     => 'email',
					'label'    => 'Email',
					'required' => true,
				],
				[
					'id'       => 'f_msg',
					'type'     => 'textarea',
					'label'    => 'Message',
					'required' => true,
				],
			]
		);

		$id = $repository->create( 'Anti spam', $schema, 'published' );

		return $repository->find( $id );
	}

	/**
	 * Standard submission fields.
	 *
	 * @return array<string, string>
	 */
	private function fields(): array {
		return [
			'sentinel_field_f_name'  => 'Ada',
			'sentinel_field_f_email' => 'ada@example.com',
			'sentinel_field_f_msg'   => 'Please send a quote.',
		];
	}

	/**
	 * Simulate a POST, optionally with a render timestamp.
	 *
	 * @param int                  $formId    Form ID.
	 * @param array<string, mixed> $fields    Field values.
	 * @param float                $startedAt Render timestamp (microtime).
	 * @return array{url?: string, status?: int, body?: string}
	 */
	private function submit( int $formId, array $fields, float $startedAt ): array {
		$_POST = array_merge(
			[
				'_sentinel_form_id'    => $formId,
				'_sentinel_form_nonce' => wp_create_nonce( 'sentinel_form_' . $formId ),
				'_sentinel_started_at' => (string) $startedAt,
			],
			$fields
		);

		add_filter(
			'sentinel_forms_redirect',
			static function ( string $url ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
				throw new RedirectedException( $url );
			}
		);

		add_filter(
			'sentinel_forms_invalid_submission_response',
			static function ( string $body, int $status ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated body, assertions only.
				throw new StoppedException( $status, $body );
			},
			10,
			2
		);

		try {
			SubmissionHandler::handle();
		} catch ( RedirectedException $redirected ) {
			return [ 'url' => $redirected->url ];
		} catch ( StoppedException $stopped ) {
			return [
				'status' => $stopped->status,
				'body'   => $stopped->body,
			];
		} finally {
			remove_all_filters( 'sentinel_forms_redirect' );
			remove_all_filters( 'sentinel_forms_invalid_submission_response' );
		}

		return [];
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'sentinel_forms_rate_limit' );
		parent::tearDown();
	}
}
