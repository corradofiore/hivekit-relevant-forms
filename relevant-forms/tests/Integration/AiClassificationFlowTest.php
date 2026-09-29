<?php
/**
 * AI classification flow integration tests (Phase 4, v2 §4).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\FormSaveController;
use RelevantForms\Cloud\CloudConnection;
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
 * Consent → classification → entry status on real WordPress.
 *
 * @group integration
 */
final class AiClassificationFlowTest extends TestCase {

	use CapturesRedirects;

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

		// Per-form guidance reaches the classifier (purpose, priorities,
		// unwanted enquiries).
		$this->assertStringContainsString( 'Automated sales pitches and link drops', $body );
		$this->assertStringContainsString( 'emergency repairs', $body );

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
	 * The AI summary (5-7 words) lands in the notification subject line.
	 */
	public function testEmailSubjectCarriesTheAiSummary(): void {
		$this->enableConsent();

		$this->classification['summary'] = 'Quote request from homeowner in Milan';

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

		try {
			$this->submit( $form->id, $this->fields() );
		} finally {
			remove_all_filters( 'pre_wp_mail' );
		}

		$this->assertNotEmpty( $emails );
		$this->assertStringContainsString( 'Enquiry', $emails[0]['subject'] );
		$this->assertStringContainsString( 'Quote request from homeowner in Milan', $emails[0]['subject'] );
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
	 * The editor save persists the per-form policy (sender + AI content) and
	 * keeps import metadata / upload policy intact.
	 */
	public function testSavingPersistsThePerFormPolicy(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$form       = $this->makeForm( true );

		// Simulate import provenance + a stored top-level upload policy.
		$repository->update(
			$form->id,
			$form->name,
			$form->schema
				->withMetadata( [ 'wpforms_form_id' => 1105 ] )
				->withUploadPolicy( [ 'legacy' => true ] ),
			'published'
		);
		$form = $repository->find( $form->id );

		$url = $this->captureRedirect(
			[ FormSaveController::class, 'handle' ],
			'relevant_form_save',
			[
				'form_id'         => $form->id,
				'form_name'       => 'Enquiry',
				'form_status'     => 'published',
				'sender_name'     => 'Acme Forms',
				'sender_email'    => 'no-reply@acme.example',
				'ai_enabled'      => '1',
				'ai_purpose'      => 'Quote requests from homeowners',
				'ai_priorities'   => 'emergency repairs',
				'ai_spam'         => 'Automated sales pitches and link drops',
				'ai_privacy_mode' => '1',
			]
		);

		$this->assertStringContainsString( 'saved=1', $url );

		$saved  = $repository->find( $form->id );
		$policy = $saved->schema->aiPolicy();

		$this->assertSame(
			[
				'name'  => 'Acme Forms',
				'email' => 'no-reply@acme.example',
			],
			$saved->schema->sender()
		);
		$this->assertSame( 'Quote requests from homeowners', $policy['purpose'] ?? '' );
		$this->assertSame( 'emergency repairs', $policy['priorities'] ?? '' );
		$this->assertSame( 'Automated sales pitches and link drops', $policy['spam'] ?? '' );
		$this->assertSame( 1, $policy['privacy_mode'] ?? 0 );

		// Non-editor keys survive the save.
		$this->assertSame( [ 'wpforms_form_id' => 1105 ], $saved->schema->metadata() );
		$this->assertSame( [ 'legacy' => true ], $saved->schema->uploadPolicy() );
	}

	/**
	 * Saving with classification on enables the site consent — there is no
	 * settings switch — and unchecking later never revokes it.
	 */
	public function testSavingWithClassificationEnablesTheConsent(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$form = $this->makeForm( true );

		$connection = new CloudConnection();
		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );

		$this->assertFalse( $connection->capabilityEnabled( CloudConnection::CAP_AI ) );

		$this->saveForm( $form->id, [ 'ai_enabled' => '1' ] );

		$this->assertTrue( ( new CloudConnection() )->capabilityEnabled( CloudConnection::CAP_AI ) );

		// Unchecking keeps the site consent: other forms may rely on it.
		$this->saveForm( $form->id, [] );

		$this->assertTrue( ( new CloudConnection() )->capabilityEnabled( CloudConnection::CAP_AI ) );
	}

	/**
	 * Save the form through the controller (redirect captured).
	 *
	 * @param int                  $formId    Form ID.
	 * @param array<string, mixed> $overrides Extra POST fields.
	 */
	private function saveForm( int $formId, array $overrides ): void {
		$this->captureRedirect(
			[ FormSaveController::class, 'handle' ],
			'relevant_form_save',
			array_merge(
				[
					'form_id'     => $formId,
					'form_name'   => 'Enquiry',
					'form_status' => 'published',
				],
				$overrides
			)
		);
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
					'enabled'      => $aiEnabled,
					'purpose'      => 'Quote requests from homeowners',
					'priorities'   => 'emergency repairs',
					'spam'         => 'Automated sales pitches and link drops',
					'privacy_mode' => 1,
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
			'relevant_field_f_name'  => 'Ada',
			'relevant_field_f_email' => 'ada@example.com',
			'relevant_field_f_msg'   => 'Please send a quote.',
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
