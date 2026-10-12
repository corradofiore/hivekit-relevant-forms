<?php
/**
 * Queued classification retry integration tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Ai\ClassificationRetry;
use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Cloud\RetryQueue;
use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Events\EventRecorder;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Http\SubmissionHandler;
use RelevantForms\Tests\Integration\Support\CapturesRedirects;
use RelevantForms\Tests\Integration\Support\RedirectedException;
use RelevantForms\Tests\Integration\Support\StoppedException;

/**
 * A paid classification is never dropped because the provider hiccupped:
 * a retryable failure is queued, replayed until it lands, applied to the
 * entry, and the operator still gets the priority interrupt it earned.
 * A refusal that repeating cannot fix is settled immediately and visibly.
 *
 * @group integration
 */
final class ClassificationRetryTest extends TestCase {

	use CapturesRedirects;

	/**
	 * Response the fake Cloud returns for /classifications.
	 *
	 * @var array{response: array<string, int>, body: string}
	 */
	private array $classification_response = [];

	/**
	 * Whether the fake Cloud returns a high-attention verdict.
	 *
	 * @var bool
	 */
	private bool $urgent = false;

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

		$this->urgent                  = false;
		$this->requests                = [];
		$this->classification_response = $this->succeed();

		update_option(
			'relevant_forms_settings',
			[
				'cloud_url'     => 'https://cloud.example.com',
				'cloud_api_key' => 'site-key',
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

				if ( str_contains( $url, '/alerts/sms' ) ) {
					return [
						'response' => [ 'code' => 202 ],
						'body'     => (string) wp_json_encode(
							[
								'success' => true,
								'data'    => [
									'delivery_id' => 12,
									'status'      => 'queued',
								],
							]
						),
					];
				}

				return $this->classification_response;
			},
			10,
			3
		);
	}

	/**
	 * The observed production failure: the provider answers with something
	 * unusable. The submission is queued, replayed, and settled later.
	 */
	public function testATransientFailureIsQueuedAndReplayedUntilItLands(): void {
		$this->enableConsent();
		$form = $this->makeForm();

		$this->classification_response = $this->failure( 502, 'classification_failed' );

		$entryId = $this->storeSubmission( $form );

		$queue = new RetryQueue();
		$this->assertSame( 1, $queue->pendingCount() );

		$entry = ( new EntryRepository() )->find( $entryId );
		$this->assertSame( Entry::STATUS_NEW, $entry->status );
		$this->assertSame( 'queued', $entry->meta['_classification_deferred']['state'] ?? '' );
		$this->assertContains( 'classification_queued', $this->events( $entryId ) );

		// The provider recovers; the next sweep applies the verdict.
		$this->classification_response = $this->succeed();
		$this->makeDue();

		ClassificationRetry::run();

		$entry = ( new EntryRepository() )->find( $entryId );
		$this->assertSame( 'relevant_enquiry', $entry->meta['_classification']['reason_code'] ?? '' );
		$this->assertSame( 61, (int) ( $entry->meta['_attention_score'] ?? 0 ) );
		$this->assertArrayNotHasKey( '_classification_deferred', $entry->meta );
		$this->assertSame( 0, $queue->pendingCount() );
		$this->assertSame( 1, (int) ( $queue->forEntry( $entryId )[0]['attempts'] ?? 0 ) );
		$this->assertContains( 'classification_recovered', $this->events( $entryId ) );
	}

	/**
	 * A verdict that lands late still interrupts the operator.
	 */
	public function testAPriorityVerdictArrivingLateStillAlerts(): void {
		$this->enableConsent( withSms: true );
		$form = $this->makeForm( withSms: true );

		$this->classification_response = $this->failure( 502, 'classification_failed' );
		$entryId                       = $this->storeSubmission( $form );

		$this->urgent                  = true;
		$this->classification_response = $this->succeed();
		$this->makeDue();

		ClassificationRetry::run();

		$entry = ( new EntryRepository() )->find( $entryId );
		$this->assertSame( Entry::STATUS_PRIORITY, $entry->status );

		$events = $this->events( $entryId );
		$this->assertContains( 'classification_recovered', $events );
		$this->assertContains( 'sms_alert_sent', $events );
		$this->assertSame( 'queued', $entry->meta['_sms_alert']['status'] ?? '' );
	}

	/**
	 * An entitlement refusal is final: queueing it would keep hammering
	 * the API for nothing, so the entry is marked and no work is queued.
	 */
	public function testAFailureThatRepeatingCannotFixIsNotQueued(): void {
		$this->enableConsent();
		$form = $this->makeForm();

		$this->classification_response = $this->failure( 402, 'subscription_required' );

		$entryId = $this->storeSubmission( $form );

		$this->assertSame( 0, ( new RetryQueue() )->pendingCount() );

		$entry = ( new EntryRepository() )->find( $entryId );
		$this->assertArrayNotHasKey( '_classification_deferred', $entry->meta );
		$this->assertContains( 'classification_failed', $this->events( $entryId ) );
	}

	/**
	 * An operator who already read the entry outranks a verdict that
	 * arrived afterwards.
	 */
	public function testAnOperatorDecisionOutranksALateVerdict(): void {
		$this->enableConsent();
		$form = $this->makeForm();

		$this->classification_response = $this->failure( 502, 'classification_failed' );
		$entryId                       = $this->storeSubmission( $form );

		( new EntryRepository() )->updateStatus( $entryId, Entry::STATUS_READ );

		$this->urgent                  = true;
		$this->classification_response = $this->succeed();
		$this->makeDue();

		ClassificationRetry::run();

		$entry = ( new EntryRepository() )->find( $entryId );
		$this->assertSame( Entry::STATUS_READ, $entry->status );

		// The verdict is still recorded — only the status is left alone.
		$this->assertArrayHasKey( '_classification', $entry->meta );
	}

	/**
	 * When the window closes the entry is left visibly unclassified
	 * instead of silently so.
	 */
	public function testTheWindowClosingLeavesAVisibleMark(): void {
		$this->enableConsent();
		$form = $this->makeForm();

		$this->classification_response = $this->failure( 502, 'classification_failed' );
		$entryId                       = $this->storeSubmission( $form );

		$this->expireQueue();

		ClassificationRetry::run();

		$entry = ( new EntryRepository() )->find( $entryId );
		$this->assertSame( 'failed', $entry->meta['_classification_deferred']['state'] ?? '' );
		$this->assertStringContainsString(
			'classification_failed',
			(string) ( $entry->meta['_classification_deferred']['error'] ?? '' )
		);
		$this->assertContains( 'classification_abandoned', $this->events( $entryId ) );

		$rows = ( new RetryQueue() )->forEntry( $entryId );
		$this->assertSame( RetryQueue::STATUS_ABANDONED, $rows[0]['status'] ?? '' );
	}

	/**
	 * A queued row is not retried before its next attempt falls due.
	 */
	public function testARowIsLeftAloneUntilItsNextAttempt(): void {
		$this->enableConsent();
		$form = $this->makeForm();

		$this->classification_response = $this->failure( 502, 'classification_failed' );
		$entryId                       = $this->storeSubmission( $form );

		$before = count( $this->requests );

		ClassificationRetry::run();

		$this->assertSame( $before, count( $this->requests ) );
		$this->assertSame( 1, ( new RetryQueue() )->pendingCount() );
	}

	/**
	 * The entry view says what is happening instead of showing a bare
	 * "no classification": queued while it is still being chased, and a
	 * warning once the window has closed.
	 */
	public function testTheEntryViewExplainsAQueuedAndAFailedClassification(): void {
		$this->enableConsent();
		$form = $this->makeForm();

		$this->classification_response = $this->failure( 502, 'classification_failed' );

		$queuedId = $this->storeSubmission( $form );
		$html     = $this->renderEntryView( $queuedId );

		$this->assertStringContainsString( 'Classification is still being retried.', $html );
		$this->assertStringContainsString( 'classification_failed', $html );

		$this->expireQueue();
		ClassificationRetry::run();

		$failed = $this->renderEntryView( $queuedId );

		$this->assertStringContainsString( 'never classified', $failed );
		$this->assertStringContainsString( 'classification_failed', $failed );
	}

	/**
	 * Render the admin entry view for one entry.
	 *
	 * @param int $entryId Entry id.
	 */
	private function renderEntryView( int $entryId ): string {
		$entries = new EntryRepository();
		$entry   = $entries->find( $entryId );
		$form    = null !== $entry ? ( new FormRepository() )->find( $entry->formId ) : null;
		$events  = EventRecorder::forEntry( $entryId );
		$uploads = [];

		ob_start();
		require RELEVANT_FORMS_PATH . 'templates/admin/entry-view.php';

		return (string) ob_get_clean();
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Submit through the real handler so the queueing path is exercised.
	 *
	 * @param Form $form Published form.
	 */
	private function storeSubmission( Form $form ): int {
		$_POST = [
			'_relevant_form_id'      => $form->id,
			'_relevant_form_nonce'   => wp_create_nonce( 'relevant_form_' . $form->id ),
			'_relevant_started_at'   => (string) ( microtime( true ) - 30.0 ),
			'relevant_field_f_name'  => 'Ada',
			'relevant_field_f_email' => 'ada@example.com',
			'relevant_field_f_msg'   => 'Please send a quote.',
		];

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

		$entries = ( new EntryRepository() )->findMany(
			[
				'form_id' => $form->id,
				'limit'   => 1,
			]
		);

		$this->assertNotEmpty( $entries, 'the submission was not stored' );

		return (int) $entries[0]->id;
	}

	/**
	 * Event names recorded for an entry.
	 *
	 * @param int $entryId Entry id.
	 * @return array<int, string>
	 */
	private function events( int $entryId ): array {
		return array_column( EventRecorder::forEntry( $entryId ), 'event' );
	}

	/**
	 * Bring every queued row's next attempt forward to now.
	 */
	private function makeDue(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'relevant_cloud_queue';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned queue table, no user input.
		$wpdb->query( "UPDATE {$table} SET next_attempt_at = '2000-01-01 00:00:00'" );
	}

	/**
	 * Close the retry window on every queued row.
	 */
	private function expireQueue(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'relevant_cloud_queue';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned queue table, no user input.
		$wpdb->query( "UPDATE {$table} SET expires_at = '2000-01-01 00:00:00'" );
	}

	/**
	 * Consent state, with the SMS capability when the test needs it.
	 *
	 * @param bool $withSms Enable the priority SMS capability.
	 */
	private function enableConsent( bool $withSms = false ): void {
		$connection = new CloudConnection();
		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );
		$connection->enableCapability( CloudConnection::CAP_AI );

		if ( $withSms ) {
			$connection->enableCapability( CloudConnection::CAP_SMS );
		}
	}

	/**
	 * Build a published form that opts into classification.
	 *
	 * @param bool $withSms Ask for a priority SMS alert too.
	 */
	private function makeForm( bool $withSms = false ): Form {
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
					'enabled'      => true,
					'purpose'      => 'Quote requests from homeowners',
					'priorities'   => 'emergency repairs',
					'spam'         => 'Automated sales pitches',
					'privacy_mode' => 1,
					'sms_alert'    => $withSms ? 1 : 0,
					'sms_phone'    => $withSms ? '+15550001111' : '',
				]
			);

		$id = $repository->create( 'Enquiry', $schema, 'published' );

		return $repository->find( $id );
	}

	/**
	 * A successful classification response.
	 *
	 * @return array{response: array<string, int>, body: string}
	 */
	private function succeed(): array {
		$verdict = $this->urgent
			? [
				'spam_probability' => 0.02,
				'relevance'        => 0.98,
				'urgency'          => 0.96,
				'intent'           => 'emergency_request',
				'category'         => 'plumbing_emergency',
				'summary'          => 'Burst pipe, needs someone today.',
				'sms_summary'      => 'Burst pipe',
				'reason_code'      => 'relevant_enquiry',
			]
			: [
				'spam_probability' => 0.02,
				'relevance'        => 0.5,
				'urgency'          => 0.3,
				'intent'           => 'question',
				'category'         => 'new_customer',
				'summary'          => 'General enquiry.',
				'reason_code'      => 'relevant_enquiry',
			];

		return [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode(
				[
					'success' => true,
					'data'    => [ 'classification' => $verdict ],
				]
			),
		];
	}

	/**
	 * A failure response from the Cloud.
	 *
	 * @param int    $code  HTTP status.
	 * @param string $error Machine-readable error code.
	 * @return array{response: array<string, int>, body: string}
	 */
	private function failure( int $code, string $error ): array {
		return [
			'response' => [ 'code' => $code ],
			'body'     => (string) wp_json_encode(
				[
					'success' => false,
					'error'   => [
						'code'    => $error,
						'message' => 'The classifier could not produce a valid result.',
					],
				]
			),
		];
	}
}
