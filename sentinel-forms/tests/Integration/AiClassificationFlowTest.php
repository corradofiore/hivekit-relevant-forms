<?php
/**
 * AI classification flow integration tests (Phase 4, v2 §4).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Admin\Controllers\FormSaveController;
use SentinelForms\Cloud\CloudConnection;
use SentinelForms\Entries\Entry;
use SentinelForms\Entries\EntryRepository;
use SentinelForms\Events\EventRecorder;
use SentinelForms\Forms\Form;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormSchema;
use SentinelForms\Http\SubmissionHandler;
use SentinelForms\Tests\Integration\Support\RedirectedException;
use SentinelForms\Tests\Integration\Support\StoppedException;

/**
 * Consent → classification → entry status on real WordPress.
 *
 * @group integration
 */
final class AiClassificationFlowTest extends TestCase {

	/**
	 * Canned classification payload returned by the fake Cloud.
	 *
	 * @var array<string, mixed>
	 */
	private array $classification = [];

	/**
	 * Requests captured by the fake Cloud.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $requests = [];

	/**
	 * Install the fake Cloud endpoint.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->classification = [
			'spam_probability' => 0.02,
			'relevance'        => 0.5,
			'urgency'          => 0.3,
			'intent'           => 'question',
			'category'         => 'new_customer',
			'summary'          => 'General enquiry.',
			'reason_code'      => 'relevant_enquiry',
		];
		$this->requests       = [];

		update_option(
			'sentinel_forms_settings',
			[
				'cloud_url'       => 'https://cloud.example.com',
				'cloud_api_key'   => 'site-key',
				'ai_site_purpose' => 'Plumbing company',
				'ai_priorities'   => 'emergency repairs',
				'ai_privacy_mode' => 1,
			]
		);

		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array {
				unset( $pre );
				$this->requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				return [
					'response' => [ 'code' => 200 ],
					'body'     => (string) wp_json_encode(
						[
							'success' => true,
							'data'    => [ 'classification' => $this->classification ],
						]
					),
				];
			},
			10,
			3
		);
	}

	/**
	 * Consented: the submission is classified, meta stored, event recorded.
	 */
	public function testConsentedSubmissionsAreClassified(): void {
		$this->enableConsent();

		$form = $this->makeForm( true );

		$this->submit( $form->id, $this->fields() );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertCount( 1, $entries );

		$entry = $entries[0];
		$this->assertSame( Entry::STATUS_NEW, $entry->status );

		$classification = $entry->meta['_classification'] ?? [];
		$this->assertSame( 'relevant_enquiry', $classification['reason_code'] ?? '' );
		$this->assertSame( 61, (int) ( $entry->meta['_attention_score'] ?? 0 ) );

		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( '/api/v1/classifications', (string) $this->requests[0]['url'] );
		$this->assertStringContainsString( 'site-key', (string) $this->requests[0]['args']['headers']['Authorization'] );

		// Redaction happened before transmission (doc §18).
		$body = (string) $this->requests[0]['args']['body'];
		$this->assertStringContainsString( '[EMAIL]', $body );
		$this->assertStringNotContainsString( 'ada@example.com', $body );

		// Per-form guidance reaches the classifier (purpose + spam criteria).
		$this->assertStringContainsString( 'Automated sales pitches and link drops', $body );

		$names = array_column( EventRecorder::forEntry( (int) $entry->id ), 'event' );
		$this->assertContains( 'classification_completed', $names );
	}

	/**
	 * Not consented: zero external requests, entry untouched (v2 §7-8).
	 */
	public function testNoRequestWithoutExplicitConsent(): void {
		$form = $this->makeForm( true );

		$this->submit( $form->id, $this->fields() );

		$this->assertCount( 0, $this->requests );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertCount( 1, $entries );

		$entry = $entries[0];
		$this->assertArrayNotHasKey( '_classification', $entry->meta );
		$this->assertSame( Entry::STATUS_NEW, $entry->status );

		$names = array_column( EventRecorder::forEntry( (int) $entry->id ), 'event' );
		$this->assertNotContains( 'classification_completed', $names );
	}

	/**
	 * Per-form opt-out wins even when the capability is enabled.
	 */
	public function testPerFormOptOutSkipsClassification(): void {
		$this->enableConsent();

		$form = $this->makeForm( false );

		$this->submit( $form->id, $this->fields() );

		$this->assertCount( 0, $this->requests );
	}

	/**
	 * Classification spam (>= 0.95) lands in spam and never emails (doc §20).
	 */
	public function testClassifiedSpamSkipsEmailAndMarksEntrySpam(): void {
		$this->enableConsent();

		$this->classification = [
			'spam_probability' => 0.97,
			'relevance'        => 0.1,
			'urgency'          => 0.0,
			'reason_code'      => 'seo_solicitation',
		];

		$form = $this->makeForm( true );

		$emails = [];
		add_filter(
			'pre_wp_mail',
			static function ( ?bool $short_circuit, array $args ) use ( &$emails ): bool {
				$emails[] = $args;

				return true;
			},
			10,
			2
		);

		$this->submit( $form->id, $this->fields() );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$entry   = $entries[0];

		$this->assertSame( Entry::STATUS_SPAM, $entry->status );
		$this->assertCount( 0, $emails );

		$events = array_column( EventRecorder::forEntry( (int) $entry->id ), 'event' );
		$this->assertContains( 'classification_completed', $events );
		$this->assertContains( 'notification_skipped', $events );
	}

	/**
	 * Provider failure: entry still stored, failure event recorded.
	 */
	public function testProviderFailureLeavesEntryIntact(): void {
		$this->enableConsent();

		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			static function ( mixed $pre, array $args, string $url ): array {
				unset( $pre, $args, $url );

				return [
					'response' => [ 'code' => 502 ],
					'body'     => '{"success":false}',
				];
			},
			10,
			3
		);

		$form = $this->makeForm( true );

		$this->submit( $form->id, $this->fields() );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertCount( 1, $entries );

		$entry = $entries[0];
		$this->assertSame( Entry::STATUS_NEW, $entry->status );
		$this->assertArrayNotHasKey( '_classification', $entry->meta );

		$names = array_column( EventRecorder::forEntry( (int) $entry->id ), 'event' );
		$this->assertContains( 'classification_failed', $names );
	}

	/**
	 * The editor save persists the per-form AI policy (purpose + spam).
	 */
	public function testSavingPersistsTheAiPolicy(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$form = $this->makeForm( true );

		$_POST = [
			'form_id'     => $form->id,
			'form_name'   => 'Enquiry',
			'form_status' => 'published',
			'ai_enabled'  => '1',
			'ai_purpose'  => 'Quote requests from homeowners',
			'ai_spam'     => 'Automated sales pitches and link drops',
		];

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'sentinel_form_save' );

		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
				throw new RedirectedException( $location );
			}
		);

		$url = '';

		try {
			FormSaveController::handle();
			$this->fail( 'The controller should redirect.' );
		} catch ( RedirectedException $redirected ) {
			$url = $redirected->url;
		} finally {
			remove_all_filters( 'wp_redirect' );
			unset( $_REQUEST['_wpnonce'] );
		}

		$this->assertStringContainsString( 'saved=1', $url );

		$policy = ( new FormRepository() )->find( $form->id )->schema->aiPolicy();

		$this->assertSame( 'Quote requests from homeowners', $policy['purpose'] ?? '' );
		$this->assertSame( 'Automated sales pitches and link drops', $policy['spam'] ?? '' );
	}

	/**
	 * Enable the semantic classification capability without HTTP.
	 */
	private function enableConsent(): void {
		$connection = new CloudConnection();
		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );
		$connection->enableCapability( CloudConnection::CAP_AI );
	}

	/**
	 * Build a published form with an explicit AI policy.
	 *
	 * @param bool $aiEnabled Whether the form opts into classification.
	 */
	private function makeForm( bool $aiEnabled ): Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Enquiry' )
			->withFields(
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
			)
			->withAiPolicy(
				[
					'enabled' => $aiEnabled,
					'purpose' => 'Quote requests from homeowners',
					'spam'    => 'Automated sales pitches and link drops',
				]
			);

		$id = $repository->create( 'Enquiry', $schema, 'published' );

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
	 * Simulate a POST at human speed.
	 *
	 * @param int                  $formId Form ID.
	 * @param array<string, mixed> $fields Field values.
	 * @return array{url?: string, status?: int, body?: string}
	 */
	private function submit( int $formId, array $fields ): array {
		$_POST = array_merge(
			[
				'_sentinel_form_id'    => $formId,
				'_sentinel_form_nonce' => wp_create_nonce( 'sentinel_form_' . $formId ),
				'_sentinel_started_at' => (string) ( microtime( true ) - 30.0 ),
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
		}

		return [];
	}
}
