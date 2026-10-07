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

use RelevantForms\Assets\FrontendAssets;
use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Forms\FormRenderer;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Http\SaveResumeController;
use RelevantForms\Http\SubmissionHandler;
use RelevantForms\Support\Privacy;
use RelevantForms\Tests\Integration\Support\AjaxRequests;
use RelevantForms\Tests\Integration\Support\CapturesMail;
use RelevantForms\Tests\Integration\Support\RedirectedException;

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
		$this->assertSame( [ 'save-one@example.test' ], (array) $this->sent_mail[0]['to'] );
		$this->assertStringContainsString( 'Quote request', (string) $this->sent_mail[0]['subject'] );
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
	 * Enabled forms render the button row, the per-form nonce and the
	 * browser-draft configuration — and enqueue the script.
	 */
	public function testEnabledFormsRenderTheSaveAndResumeControls(): void {
		FrontendAssets::registerAssets();

		$formId = $this->resumableForm();

		$html = FormRenderer::render( $formId );

		$this->assertStringContainsString( 'data-rf-save-resume-nonce=', $html );
		$this->assertStringContainsString( 'data-rf-drafts="1"', $html );
		$this->assertStringContainsString( 'data-rf-resume-expiry="30"', $html );
		$this->assertStringContainsString( 'data-rf-save-resume-row hidden', $html );
		$this->assertStringContainsString( 'Save and Resume', $html );
		$this->assertTrue( wp_script_is( 'relevant-forms-save-resume', 'enqueued' ) );
	}

	/**
	 * Browser drafts can be switched off per form.
	 */
	public function testBrowserDraftsCanBeDisabledPerForm(): void {
		$formId = $this->resumableForm( [], [ 'drafts_in_browser' => false ] );

		$html = FormRenderer::render( $formId );

		$this->assertStringContainsString( 'data-rf-drafts="0"', $html );
	}

	/**
	 * Forms without the toggle render nothing of the feature.
	 */
	public function testDisabledFormsDoNotRenderTheControls(): void {
		FrontendAssets::registerAssets();
		wp_dequeue_script( 'relevant-forms-save-resume' );

		$formId = ( new FormRepository() )->create(
			'Plain',
			FormSchema::empty( 'Plain' )->withFields( [ $this->nameField() ] ),
			'published'
		);

		$html = FormRenderer::render( $formId );

		$this->assertStringNotContainsString( 'data-rf-save-resume-nonce', $html );
		$this->assertStringNotContainsString( 'data-rf-save-resume-row', $html );
		$this->assertFalse( wp_script_is( 'relevant-forms-save-resume', 'enqueued' ) );
	}

	/**
	 * A valid resume link restores the saved values, the token and the
	 * wizard page, and shows the welcome-back notice.
	 */
	public function testAValidResumeLinkRestoresTheSavedValues(): void {
		$this->captureMail();

		$formId = $this->resumableForm(
			[
				[
					'id'    => 'f_break',
					'type'  => 'pagebreak',
					'label' => 'More',
				],
				[
					'id'    => 'f_note',
					'type'  => 'text',
					'label' => 'Note',
				],
			]
		);

		$save = $this->dispatch(
			$formId,
			[
				'save_resume_email'     => 'save-eleven@example.test',
				'save_resume_page'      => 2,
				'relevant_field_f_name' => 'Ada Lovelace',
			]
		);

		unset( $_POST );

		$_GET['relevant_resume'] = $save['data']['token'];
		$_GET['relevant_form']   = $formId;

		$html = FormRenderer::render( $formId );

		$this->assertStringContainsString( 'value="Ada Lovelace"', $html );
		$this->assertStringContainsString( 'data-rf-resume-token="' . $save['data']['token'] . '"', $html );
		$this->assertStringContainsString( 'data-rf-initial-page="2"', $html );
		$this->assertStringContainsString( 'we restored the progress you saved on', $html );
	}

	/**
	 * Unknown or expired tokens show the friendly warning without prefill.
	 */
	public function testUnknownResumeTokensShowTheWarning(): void {
		$formId = $this->resumableForm();

		$_GET['relevant_resume'] = str_repeat( 'a', 64 );
		$_GET['relevant_form']   = $formId;

		$html = FormRenderer::render( $formId );

		$this->assertStringContainsString( 'This resume link is no longer valid', $html );
		$this->assertStringNotContainsString( 'data-rf-resume-token', $html );
	}

	/**
	 * A resume link for another form on the same page is ignored.
	 */
	public function testResumeLinksOnlyApplyToTheirOwnForm(): void {
		$formId = $this->resumableForm();

		$_GET['relevant_resume'] = str_repeat( 'b', 64 );
		$_GET['relevant_form']   = $formId + 999;

		$html = FormRenderer::render( $formId );

		$this->assertStringNotContainsString( 'This resume link is no longer valid', $html );
	}

	/**
	 * Submitting a resumed form converts the partial entry in place: one
	 * entry, final values, resume state cleared, notifications fired.
	 */
	public function testSubmittingAResumedFormConvertsThePartialEntryInPlace(): void {
		$this->captureMail();

		$formId = $this->resumableForm();

		$save = $this->dispatch(
			$formId,
			[
				'save_resume_email'     => 'save-twelve@example.test',
				'relevant_field_f_name' => 'Ada',
			]
		);

		$partial = ( new EntryRepository() )->findPartialByEmail( $formId, 'save-twelve@example.test' );

		$this->assertInstanceOf( Entry::class, $partial );

		// From here on only the submission notification matters.
		$this->stopCapturingMail();
		$this->sent_mail = [];
		$this->captureMail();

		$this->submit(
			$formId,
			[
				'relevant_field_f_name'  => 'Ada Lovelace',
				'_relevant_resume_token' => $save['data']['token'],
			]
		);

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $formId ] );

		$this->assertCount( 1, $entries );
		$this->assertSame( (int) $partial->id, (int) $entries[0]->id );
		$this->assertSame( Entry::STATUS_NEW, $entries[0]->status );
		$this->assertSame( 'Ada Lovelace', $entries[0]->meta['f_name'] );
		$this->assertSame( 1, $entries[0]->meta['_resumed'] );
		$this->assertNull( $entries[0]->resumeTokenHash );
		$this->assertNull( $entries[0]->resumeEmail );
		$this->assertNull( $entries[0]->resumeExpiresAt );
		$this->assertCount( 1, $this->sent_mail );
	}

	/**
	 * Stale or tampered tokens fall back to a fresh entry; the partial entry
	 * stays untouched.
	 */
	public function testTamperedResumeTokensFallBackToANewEntry(): void {
		$formId = $this->resumableForm();

		$this->dispatch(
			$formId,
			[
				'save_resume_email'     => 'save-thirteen@example.test',
				'relevant_field_f_name' => 'Ada',
			]
		);

		$this->submit(
			$formId,
			[
				'relevant_field_f_name'  => 'Grace',
				'_relevant_resume_token' => str_repeat( 'c', 64 ),
			]
		);

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $formId ] );

		$this->assertCount( 2, $entries );

		$partial = ( new EntryRepository() )->findPartialByEmail( $formId, 'save-thirteen@example.test' );

		$this->assertInstanceOf( Entry::class, $partial );
		$this->assertSame( Entry::STATUS_PARTIAL, $partial->status );

		$submitted = array_values(
			array_filter( $entries, static fn ( Entry $entry ): bool => Entry::STATUS_NEW === $entry->status )
		);

		$this->assertCount( 1, $submitted );
		$this->assertSame( 'Grace', $submitted[0]->meta['f_name'] );
	}

	/**
	 * Simulate a front-end submission POST (expected to succeed).
	 *
	 * @param int                  $formId Form ID.
	 * @param array<string, mixed> $fields Field values plus extra POST keys.
	 */
	private function submit( int $formId, array $fields ): void {
		$_POST = array_merge(
			[
				'_relevant_form_id'    => $formId,
				'_relevant_form_nonce' => wp_create_nonce( 'relevant_form_' . $formId ),
			],
			$fields
		);

		add_filter(
			'relevant_forms_redirect',
			static function ( string $url ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated redirect captured for assertions.
				throw new RedirectedException( $url );
			}
		);

		try {
			SubmissionHandler::handle();
		} catch ( RedirectedException $redirected ) {
			$this->assertStringContainsString( 'relevant_form_success=' . $formId, $redirected->url );
		} finally {
			remove_all_filters( 'relevant_forms_redirect' );
			unset( $_POST );
		}
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
		unset( $_POST, $_GET['relevant_resume'], $_GET['relevant_form'] );

		parent::tearDown();
	}
}
