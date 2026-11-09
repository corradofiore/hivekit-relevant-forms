<?php
/**
 * Managed priority SMS integration tests (Phase 5, doc §20–22).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Events\EventRecorder;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Http\SubmissionHandler;
use RelevantForms\Tests\Integration\Support\RedirectedException;
use RelevantForms\Tests\Integration\Support\StoppedException;

/**
 * Attention threshold → minimal alert → Cloud delivery on real WordPress.
 *
 * @group integration
 */
final class SmsAlertFlowTest extends TestCase {

	/**
	 * Classification returned by the fake Cloud.
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
	 * Install the fake Cloud endpoints.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->classification = [
			'spam_probability' => 0.02,
			'relevance'        => 0.9,
			'urgency'          => 0.9,
			'summary'          => 'Burst pipe in the basement.',
			'sms_summary'      => 'Burst pipe',
			'reason_code'      => 'relevant_enquiry',
		];
		$this->requests       = [];

		update_option(
			'relevant_forms_settings',
			[
				'cloud_url'     => 'https://cloud.example.com',
				'cloud_api_key' => 'site-key',
			]
		);

		$connection = new CloudConnection();
		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );
		$connection->enableCapability( CloudConnection::CAP_AI );
		$connection->enableCapability( CloudConnection::CAP_SMS );

		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array {
				unset( $pre );
				$this->requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				if ( str_contains( $url, '/alerts/sms' ) ) {
					return [
						'response' => [ 'code' => 202 ],
						'body'     => (string) wp_json_encode(
							[
								'success' => true,
								'data'    => [
									'delivery_id' => 41,
									'status'      => 'queued',
								],
							]
						),
					];
				}

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
	 * An urgent submission triggers exactly one managed alert.
	 */
	public function test_urgent_submissions_trigger_a_managed_alert(): void {
		$form = $this->makeForm();

		$this->submit( $form->id, $this->fields() );

		$entry  = $this->latestEntry( $form );
		$alerts = $this->alertRequests();

		$this->assertCount( 1, $alerts );

		$payload = json_decode( (string) $alerts[0]['args']['body'], true );
		$this->assertSame( '+3912345', $payload['recipient'] );
		// The message carries the short summary; the email carries the longer one.
		$this->assertSame( 'Burst pipe', $payload['alert']['summary'] );
		$this->assertStringContainsString(
			'page=relevant-entry&entry_id=' . (string) $entry->id,
			(string) $payload['alert']['entry_url']
		);
		$this->assertGreaterThanOrEqual( 85, (int) $payload['alert']['attention_score'] );

		$sms_meta = $entry->meta['_sms_alert'] ?? [];
		$this->assertSame( 'queued', $sms_meta['status'] ?? '' );
		$this->assertSame( 41, $sms_meta['delivery_id'] ?? 0 );

		$names = array_column( EventRecorder::forEntry( (int) $entry->id ), 'event' );
		$this->assertContains( 'sms_alert_sent', $names );
	}

	/**
	 * Without the capability no alert is attempted (v2 §7-8).
	 */
	public function test_no_alert_without_the_capability(): void {
		$connection = new CloudConnection();
		$connection->disableCapability( CloudConnection::CAP_SMS );

		$form = $this->makeForm();

		$this->submit( $form->id, $this->fields() );

		$this->assertCount( 0, $this->alertRequests() );

		$entry = $this->latestEntry( $form );
		$this->assertArrayNotHasKey( '_sms_alert', $entry->meta );
	}

	/**
	 * A form without its own verified number never alerts (no fallback).
	 */
	public function test_no_alert_without_a_per_form_recipient(): void {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'No Number' )
			->withFields(
				[
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
					'enabled'   => true,
					'sms_alert' => true,
				]
			);

		$id   = $repository->create( 'No Number', $schema, 'published' );
		$form = $repository->find( $id );

		$this->submit( $form->id, $this->fields() );

		$this->assertCount( 0, $this->alertRequests() );

		$entry = $this->latestEntry( $form );
		$this->assertArrayNotHasKey( '_sms_alert', $entry->meta );
	}

	/**
	 * Ordinary legitimate submissions never interrupt the operator.
	 */
	public function test_attention_below_threshold_does_not_alert(): void {
		$this->classification['urgency']   = 0.1;
		$this->classification['relevance'] = 0.5;

		$form = $this->makeForm();

		$this->submit( $form->id, $this->fields() );

		$this->assertCount( 0, $this->alertRequests() );

		$entry = $this->latestEntry( $form );
		$this->assertArrayNotHasKey( '_sms_alert', $entry->meta );
	}

	/**
	 * A provider failure is recorded but never affects the entry (doc §57).
	 */
	public function test_provider_failure_is_recorded_and_entry_survives(): void {
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array {
				unset( $pre );
				$this->requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				if ( str_contains( $url, '/alerts/sms' ) ) {
					return [
						'response' => [ 'code' => 502 ],
						'body'     => '{"success":false}',
					];
				}

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

		$form = $this->makeForm();

		$this->submit( $form->id, $this->fields() );

		$entry = $this->latestEntry( $form );

		$this->assertSame( Entry::STATUS_PRIORITY, $entry->status );

		$sms_meta = $entry->meta['_sms_alert'] ?? [];
		$this->assertSame( 'failed', $sms_meta['status'] ?? '' );

		$names = array_column( EventRecorder::forEntry( (int) $entry->id ), 'event' );
		$this->assertContains( 'sms_alert_failed', $names );
		$this->assertContains( 'classification_completed', $names );
	}

	/**
	 * Requests sent to the managed SMS endpoint.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function alertRequests(): array {
		return array_values(
			array_filter(
				$this->requests,
				static fn ( array $request ): bool => str_contains( (string) $request['url'], '/alerts/sms' )
			)
		);
	}

	/**
	 * Newest stored entry for the form.
	 */
	private function latestEntry( Form $form ): Entry {
		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertNotEmpty( $entries );

		usort( $entries, static fn ( Entry $a, Entry $b ): int => (int) $b->id <=> (int) $a->id );

		return $entries[0];
	}

	/**
	 * Build a published form with AI classification enabled.
	 */
	private function makeForm(): Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Emergency Request' )
			->withFields(
				[
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
					'enabled'   => true,
					'sms_alert' => true,
					'sms_phone' => '+3912345',
				]
			);

		$id = $repository->create( 'Emergency Request', $schema, 'published' );

		return $repository->find( $id );
	}

	/**
	 * Standard submission fields.
	 *
	 * @return array<string, string>
	 */
	private function fields(): array {
		return [ 'relevant_field_f_msg' => 'Water is coming through the ceiling.' ];
	}

	/**
	 * Simulate a POST at human speed.
	 *
	 * @param int                  $formId Form ID.
	 * @param array<string, mixed> $fields Field values.
	 */
	private function submit( int $formId, array $fields ): void {
		$_POST = array_merge(
			[
				'_relevant_form_id'    => $formId,
				'_relevant_form_nonce' => wp_create_nonce( 'relevant_form_' . $formId ),
				'_relevant_started_at' => (string) ( microtime( true ) - 30.0 ),
			],
			$fields
		);

		add_filter(
			'relevant_forms_redirect',
			static function ( string $url ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
				throw new RedirectedException( $url );
			}
		);

		add_filter(
			'relevant_forms_invalid_submission_response',
			static function ( string $body, int $status ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated body, assertions only.
				throw new StoppedException( $status, $body );
			},
			10,
			2
		);

		try {
			SubmissionHandler::handle();
		} catch ( RedirectedException | StoppedException $stopped ) {
			unset( $stopped );
		} finally {
			remove_all_filters( 'relevant_forms_redirect' );
			remove_all_filters( 'relevant_forms_invalid_submission_response' );
		}
	}

	/**
	 * Tear down filters.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}
}
