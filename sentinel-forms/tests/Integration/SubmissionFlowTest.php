<?php
/**
 * Submission flow integration tests.
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
 * Submission flow integration tests: validation, persistence, honeypot, nonce.
 *
 * @group integration
 */
final class SubmissionFlowTest extends TestCase {

	/**
	 * Test a legitimate submission is persisted and redirects with success.
	 */
	public function testLegitimateSubmission(): void {
		$form = $this->makePublishedForm();

		$emails = [];
		add_filter(
			'pre_wp_mail',
			static function ( ?bool $shortCircuit, array $args ) use ( &$emails ): bool {
				$emails[] = $args;

				return true; // Short-circuit: skip real delivery.
			},
			10,
			2
		);

		$url = $this->submit(
			$form->id,
			[
				'sentinel_field_f_name'    => 'Ada Lovelace',
				'sentinel_field_f_email'   => 'ada@example.com',
				'sentinel_field_f_message' => 'I need a boiler repair.',
			]
		);

		$this->assertStringContainsString( 'sentinel_form_success=' . $form->id, $url );

		$repository = new EntryRepository();
		$entries    = $repository->findMany( [ 'form_id' => $form->id ] );

		$this->assertCount( 1, $entries );

		$entry = $entries[0];
		$this->assertSame( Entry::STATUS_NEW, $entry->status );
		$this->assertSame( 'Ada Lovelace', $entry->meta['f_name'] );
		$this->assertSame( 'ada@example.com', $entry->meta['f_email'] );

		$events = EventRecorder::forEntry( (int) $entry->id );
		$names  = array_column( $events, 'event' );
		$this->assertContains( 'submission_received', $names );
		$this->assertContains( 'notification_sent', $names );

		$this->assertNotEmpty( $emails );
		$this->assertStringContainsString( 'New submission', $emails[0]['subject'] );
	}

	/**
	 * Test validation errors prevent persistence.
	 */
	public function testInvalidSubmission(): void {
		$form = $this->makePublishedForm();

		$response = $this->submit(
			$form->id,
			[
				'sentinel_field_f_name'    => '',
				'sentinel_field_f_email'   => 'not-an-email',
				'sentinel_field_f_message' => 'Hello',
			],
			false
		);

		$this->assertSame( 422, $response['status'] );
		$this->assertStringContainsString( 'sentinel-forms-error', $response['body'] );

		$repository = new EntryRepository();
		$this->assertCount( 0, $repository->findMany( [ 'form_id' => $form->id ] ) );
	}

	/**
	 * Test honeypot-triggered submissions are dropped without persistence.
	 */
	public function testHoneypotSubmission(): void {
		$form = $this->makePublishedForm();

		$url = $this->submit(
			$form->id,
			[
				'sentinel_field_f_name'    => 'Spambot',
				'sentinel_field_f_email'   => 'bot@example.com',
				'sentinel_field_f_message' => 'Buy links!',
				'_sentinel_hp'             => 'https://spam.example',
			]
		);

		$this->assertStringContainsString( 'sentinel_form_success=' . $form->id, $url );

		$repository = new EntryRepository();
		$this->assertCount( 0, $repository->findMany( [ 'form_id' => $form->id ] ) );
	}

	/**
	 * Test invalid nonces are rejected.
	 */
	public function testInvalidNonceIsRejected(): void {
		$form = $this->makePublishedForm();

		$_POST = [
			'_sentinel_form_id'        => $form->id,
			'_sentinel_form_nonce'     => 'bogus',
			'sentinel_field_f_name'    => 'Ada',
			'sentinel_field_f_email'   => 'ada@example.com',
			'sentinel_field_f_message' => 'Hello',
		];

		$this->expectException( \WPDieException::class );

		SubmissionHandler::handle();
	}

	/**
	 * Create a published form with three fields.
	 */
	private function makePublishedForm(): \SentinelForms\Forms\Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Emergency Request' )->withFields(
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
					'id'       => 'f_message',
					'type'     => 'textarea',
					'label'    => 'Message',
					'required' => true,
				],
			]
		);

		$id = $repository->create( 'Emergency Request', $schema, 'published' );

		return $repository->find( $id );
	}

	/**
	 * Simulate a submission POST.
	 *
	 * @param int                  $formId       Form ID.
	 * @param array<string, mixed> $fields       Field values (plus optional honeypot).
	 * @param bool                 $expectFinish Expect the success redirect.
	 * @return array{url?: string, status?: int, body?: string}
	 */
	private function submit( int $formId, array $fields, bool $expectFinish = true ): array {
		$_POST = array_merge(
			[
				'_sentinel_form_id'    => $formId,
				'_sentinel_form_nonce' => wp_create_nonce( 'sentinel_form_' . $formId ),
			],
			$fields
		);
		// Intercept the 422 invalid-submission response.
		add_filter(
			'sentinel_forms_invalid_submission_response',
			static function ( string $body, int $status ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- values are handler-generated and only used in test assertions.
				throw new StoppedException( $status, $body );
			},
			10,
			2
		);

		try {
			if ( $expectFinish ) {
				add_filter(
					'sentinel_forms_redirect',
					static function ( string $url ): string {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- URL is a handler-generated redirect target captured for assertions.
						throw new RedirectedException( $url );
					}
				);
			}

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

		return [
			'body' => $body,
		];
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'sentinel_forms_redirect' );
		parent::tearDown();
	}
}
