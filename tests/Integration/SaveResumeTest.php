<?php
/**
 * Save and Resume integration tests: the public "Save and Resume" endpoint
 * stores a partial entry, emails the resume link, and reuses the same entry
 * on repeated saves.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Http\SaveResumeController;
use RelevantForms\Support\Privacy;
use RelevantForms\Tests\Integration\Support\AjaxRequests;
use RelevantForms\Tests\Integration\Support\CapturesMail;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class SaveResumeTest extends TestCase {

	use CapturesMail;

	/**
	 * Reset the per-IP save throttle between tests.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_transient( 'relevant_forms_resume_ip_' . md5( Privacy::ipHash() ) );
	}

	/**
	 * Saving progress stores a partial entry and emails the resume link.
	 */
	public function testSavingProgressStoresAPartialEntryAndEmailsTheLink(): void {
		$this->captureMail();

		$formId = $this->resumableForm();

		$response = $this->dispatch(
			$formId,
			[
				'save_resume_email'      => 'save-one@example.test',
				'save_resume_page'       => 2,
				'save_resume_source_url' => 'https://example.test/quote/',
				'relevant_field_f_name'  => 'Ada Lovelace',
			]
		);

		$this->assertTrue( $response['success'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $response['data']['token'] );
		$this->assertTrue( $response['data']['emailed'] );
		$this->assertStringContainsString( 'save-one@example.test', $response['data']['message'] );

		$entry = ( new EntryRepository() )->findPartialByEmail( $formId, 'save-one@example.test' );

		$this->assertInstanceOf( Entry::class, $entry );
		$this->assertSame( Entry::STATUS_PARTIAL, $entry->status );
		$this->assertSame( 2, $entry->resumePage );
		$this->assertSame( 'save-one@example.test', $entry->resumeEmail );
		$this->assertSame( 'https://example.test/quote/', $entry->sourceUrl );
		$this->assertSame( 'Ada Lovelace', $entry->meta['f_name'] );
		$this->assertNotNull( $entry->resumeExpiresAt );
		$this->assertNotNull( $entry->resumeEmailSentAt );

		$this->assertCount( 1, $this->sent_mail );
		$this->assertSame( [ 'save-one@example.test' ], (array) $this->sent_mail[0]['to'] );		$this->assertStringContainsString( 'Quote request', (string) $this->sent_mail[0]['subject'] );
		$this->assertStringContainsString(
			'relevant_resume=' . $response['data']['token'],
			(string) $this->sent_mail[0]['message']
		);
		$this->assertStringContainsString(
			'relevant_form=' . $formId,
			(string) $this->sent_mail[0]['message']
		);
	}

	/**
	 * Saving twice from the same browser updates one entry and keeps the
	 * token, so the link the user already received stays valid.
	 */
	public function testRepeatedSavesUpdateTheSameEntryAndKeepTheToken(): void {
		$this->captureMail();

		$formId = $this->resumableForm();

		$first = $this->dispatch(
			$formId,
			[
				'save_resume_email'     => 'save-two@example.test',
				'save_resume_page'      => 1,
				'relevant_field_f_name' => 'Ada',
			]
		);

		$second = $this->dispatch(
			$formId,
			[
				'save_resume_email'     => 'save-two@example.test',
				'save_resume_page'      => 3,
				'save_resume_token'     => $first['data']['token'],
				'relevant_field_f_name' => 'Ada Lovelace',
			]
		);

		$this->assertTrue( $second['success'] );
		$this->assertSame( $first['data']['token'], $second['data']['token'] );

		$entry = ( new EntryRepository() )->findPartialByEmail( $formId, 'save-two@example.test' );

		$this->assertInstanceOf( Entry::class, $entry );
		$this->assertSame( 3, $entry->resumePage );
		$this->assertSame( 'Ada Lovelace', $entry->meta['f_name'] );

		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'relevant_entries WHERE form_id = %d AND status = %s',
				$formId,
				Entry::STATUS_PARTIAL
			)
		);

		$this->assertSame( 1, $count );
	}

	/**
	 * Uploads are never part of a partial save, and fields hidden by
	 * conditional logic keep no value.
	 */
	public function testFileFieldsAndConditionHiddenFieldsAreNotSaved(): void {
		$formId = $this->resumableForm(
			[
				[
					'id'    => 'f_cv',
					'type'  => 'file',
					'label' => 'CV',
				],
				[
					'id'         => 'f_extra',
					'type'       => 'text',
					'label'      => 'Extra',
					'visibility' => [
						'default' => 'hidden',
						'groups'  => [
							[
								[
									'field'    => 'f_name',
									'operator' => 'is',
									'value'    => 'pro',
								],
							],
						],
					],
				],
			]
		);

		$this->dispatch(
			$formId,
			[
				'save_resume_email'        => 'save-three@example.test',
				'relevant_field_f_name'    => 'Ada',
				'relevant_upload_f_cv_ids' => '12',
				'relevant_field_f_extra'   => 'sneaky',
			]
		);

		$entry = ( new EntryRepository() )->findPartialByEmail( $formId, 'save-three@example.test' );

		$this->assertInstanceOf( Entry::class, $entry );
		$this->assertArrayHasKey( 'f_name', $entry->meta );
		$this->assertArrayNotHasKey( 'f_cv', $entry->meta );
		$this->assertArrayNotHasKey( 'f_extra', $entry->meta );
	}

	/**
	 * Invalid email addresses are rejected before anything is stored.
	 */
	public function testInvalidEmailsAreRejected(): void {
		$formId = $this->resumableForm();

		$response = $this->dispatch( $formId, [ 'save_resume_email' => 'not-an-email' ] );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Enter a valid email address.', $response['data']['message'] );

		$this->assertNull( ( new EntryRepository() )->findPartialByEmail( $formId, 'not-an-email' ) );
	}

	/**
	 * Requests without a valid per-form nonce are refused.
	 */
	public function testAValidNonceIsRequired(): void {
		$formId = $this->resumableForm();

		$response = $this->dispatch(
			$formId,
			[
				'save_resume_email'      => 'save-four@example.test',
				'_relevant_resume_nonce' => 'bogus',
			]
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Security check failed.', $response['data']['message'] );
	}

	/**
	 * Forms without the Save and Resume toggle stay inert.
	 */
	public function testSavesAreRejectedWhenTheFormHasSaveAndResumeDisabled(): void {
		$formId = ( new FormRepository() )->create(
			'Plain',
			FormSchema::empty( 'Plain' )->withFields( [ $this->nameField() ] ),
			'published'
		);

		$response = $this->dispatch( $formId, [ 'save_resume_email' => 'save-five@example.test' ] );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Saving is not available for this form.', $response['data']['message'] );
	}

	/**
	 * The per-IP rate limit blocks abusive save loops.
	 */
	public function testThePerIpRateLimitBlocksAbuse(): void {
		$formId = $this->resumableForm();

		add_filter( 'relevant_forms_save_resume_rate_limit', static fn (): int => 1 );
		add_filter( 'relevant_forms_save_resume_email_rate_limit', static fn (): int => 0 );

		try {
			$first  = $this->dispatch( $formId, [ 'save_resume_email' => 'save-six@example.test' ] );
			$second = $this->dispatch( $formId, [ 'save_resume_email' => 'save-seven@example.test' ] );
		} finally {
			remove_all_filters( 'relevant_forms_save_resume_rate_limit' );
			remove_all_filters( 'relevant_forms_save_resume_email_rate_limit' );
		}

		$this->assertTrue( $first['success'] );
		$this->assertFalse( $second['success'] );
		$this->assertStringContainsString( 'Too many save requests', $second['data']['message'] );
	}

	/**
	 * When the per-address email limit is hit, the progress is still saved;
	 * only the email is skipped, and the response says so.
	 */
	public function testThePerEmailRateLimitStillSavesButSkipsTheEmail(): void {
		$this->captureMail();

		$formId = $this->resumableForm();

		add_filter( 'relevant_forms_save_resume_email_rate_limit', static fn (): int => 1 );

		try {
			$first  = $this->dispatch( $formId, [ 'save_resume_email' => 'save-eight@example.test' ] );
			$second = $this->dispatch(
				$formId,
				[
					'save_resume_email' => 'save-eight@example.test',
					'save_resume_token' => $first['data']['token'],
				]
			);
		} finally {
			remove_all_filters( 'relevant_forms_save_resume_email_rate_limit' );
		}

		$this->assertTrue( $second['success'] );
		$this->assertFalse( $second['data']['emailed'] );
		$this->assertStringContainsString( 'could not be sent', $second['data']['message'] );
		$this->assertCount( 1, $this->sent_mail );
	}

	/**
	 * Per-form subject and body replace the shipped defaults, and the
	 * placeholders are substituted.
	 */
	public function testPerFormEmailCopyOverridesTheShippedDefaults(): void {
		$this->captureMail();

		$formId = $this->resumableForm(
			[],
			[
				'email_subject' => 'Resume {form_name}',
				'email_body'    => 'Continue where you left off: {resume_link} — valid {expiry_days} days.',
			]
		);

		$this->dispatch( $formId, [ 'save_resume_email' => 'save-nine@example.test' ] );

		$this->assertCount( 1, $this->sent_mail );
		$this->assertSame( 'Resume Quote request', $this->sent_mail[0]['subject'] );
		$this->assertStringContainsString( 'valid 30 days', (string) $this->sent_mail[0]['message'] );
		$this->assertStringContainsString( 'relevant_resume=', (string) $this->sent_mail[0]['message'] );
	}

	/**
	 * The email payload filter can rewrite the message before it is sent.
	 */
	public function testTheEmailPayloadFilterCanCustomizeTheMessage(): void {
		$this->captureMail();

		$formId = $this->resumableForm();

		$filter = static function ( array $email ): array {
			$email['subject'] = 'Filtered resume email';

			return $email;
		};

		add_filter( 'relevant_forms_save_resume_email', $filter );

		try {
			$this->dispatch( $formId, [ 'save_resume_email' => 'save-ten@example.test' ] );
		} finally {
			remove_filter( 'relevant_forms_save_resume_email', $filter );
		}

		$this->assertCount( 1, $this->sent_mail );
		$this->assertSame( 'Filtered resume email', $this->sent_mail[0]['subject'] );
	}

	/**
	 * POST to the Save and Resume endpoint.
	 *
	 * @param int                  $formId  Form ID.
	 * @param array<string, mixed> $payload Extra fields.
	 * @return array<string, mixed> Decoded response body.
	 */
	private function dispatch( int $formId, array $payload ): array {
		$post = [
			...$payload,
			'_relevant_form_id' => $formId,
		];

		if ( ! array_key_exists( '_relevant_resume_nonce', $payload ) ) {
			$post['_relevant_resume_nonce'] = wp_create_nonce( 'relevant_save_resume_' . $formId );
		}

		$_POST = $post;

		return AjaxRequests::dispatch( [ SaveResumeController::class, 'handle' ] );
	}

	/**
	 * Text field used by most fixtures.
	 *
	 * @return array<string, mixed>
	 */
	private function nameField(): array {
		return [
			'id'    => 'f_name',
			'type'  => 'text',
			'label' => 'Name',
		];
	}

	/**
	 * Create a published form with Save and Resume enabled.
	 *
	 * @param array<int, array<string, mixed>> $extraFields Additional fields.
	 * @param array<string, mixed>             $saveResume  Save and Resume overrides.
	 */
	private function resumableForm( array $extraFields = [], array $saveResume = [] ): int {
		$schema = FormSchema::empty( 'Quote request' )
			->withFields( [ $this->nameField(), ...$extraFields ] )
			->withSaveResume(
				[
					'enabled' => true,
					...$saveResume,
				]
			);

		return ( new FormRepository() )->create( 'Quote request', $schema, 'published' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_POST );

		parent::tearDown();
	}
}
