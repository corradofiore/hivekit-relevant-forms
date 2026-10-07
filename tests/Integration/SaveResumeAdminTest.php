<?php
/**
 * Save and Resume admin surfaces: editor settings, entries list, entry view,
 * the resend action and the expiry cleanup.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\FormSaveController;
use RelevantForms\Admin\Controllers\ResumeLinkController;
use RelevantForms\Admin\Pages\EntryListPage;
use RelevantForms\Admin\Pages\EntryViewPage;
use RelevantForms\Admin\Pages\FormEditPage;
use RelevantForms\Entries\Cleanup\PartialEntryCleanup;
use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Entries\Resume\ResumeTokens;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Tests\Integration\Support\AjaxRequests;
use RelevantForms\Tests\Integration\Support\CapturesMail;
use RelevantForms\Tests\Integration\Support\CapturesRedirects;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class SaveResumeAdminTest extends TestCase {

	use CapturesMail;
	use CapturesRedirects;

	/**
	 * Sign in as an administrator.
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Editor saves persist the Save and Resume settings.
	 */
	public function testTheEditorPersistsTheSaveAndResumeSettings(): void {
		$formId = $this->form();

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_form_save' );
		$_POST                = [
			'form_id'                       => $formId,
			'form_name'                     => 'Resumable',
			'form_status'                   => 'published',
			'save_resume_enabled'           => '1',
			'save_resume_label'             => 'Come back later',
			'save_resume_expiry_days'       => '7',
			'save_resume_email_subject'     => 'Resume {form_name}',
			'save_resume_email_body'        => 'Continue: {resume_link}',
			'save_resume_drafts_in_browser' => '1',
		];

		$response = AjaxRequests::dispatch( [ FormSaveController::class, 'handleAjax' ] );

		$this->assertTrue( $response['success'] );

		$config = ( new FormRepository() )->find( $formId )->schema->saveResume();

		$this->assertTrue( $config['enabled'] );
		$this->assertSame( 'Come back later', $config['label'] );
		$this->assertSame( 7, $config['expiry_days'] );
		$this->assertSame( 'Resume {form_name}', $config['email_subject'] );
		$this->assertSame( 'Continue: {resume_link}', $config['email_body'] );
		$this->assertTrue( $config['drafts_in_browser'] );

		// Unchecking "Keep a draft in this browser" turns the drafts off.
		$_POST = [
			'form_id'             => $formId,
			'form_name'           => 'Resumable',
			'form_status'         => 'published',
			'save_resume_enabled' => '1',
		];

		AjaxRequests::dispatch( [ FormSaveController::class, 'handleAjax' ] );

		$config = ( new FormRepository() )->find( $formId )->schema->saveResume();

		$this->assertFalse( $config['drafts_in_browser'] );
		$this->assertSame( 30, $config['expiry_days'] );
	}

	/**
	 * The form editor renders the Save and Resume section with the stored
	 * values.
	 */
	public function testTheEditorRendersTheSaveAndResumeSection(): void {
		$formId = $this->form(
			[
				'enabled' => true,
				'label'   => 'Come back later',
			]
		);

		$_GET['form_id'] = $formId;

		ob_start();
		FormEditPage::render();
		$html = (string) ob_get_clean();

		$position = strpos( $html, 'save_resume_enabled' );

		$this->assertNotFalse( $position, 'The Save and Resume checkbox is missing from the editor.' );
		$this->assertMatchesRegularExpression(
			'/<input type="checkbox" name="save_resume_enabled" value="1"\s+checked/',
			$html
		);
		$this->assertStringContainsString( 'value="Come back later"', $html );
		$this->assertStringContainsString( 'name="save_resume_expiry_days"', $html );
		$this->assertStringContainsString( 'name="save_resume_email_subject"', $html );
		$this->assertStringContainsString( 'name="save_resume_email_body"', $html );
		$this->assertStringContainsString( 'Save and Resume', $html );
	}

	/**
	 * Partial entries show up in the entries list with their own status.
	 */
	public function testPartialEntriesAppearInTheListWithTheirLabel(): void {
		$formId = $this->form();
		$this->partial( $formId );

		$_GET['status'] = 'partial';

		ob_start();
		EntryListPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'relevant-forms-status--partial', $html );
		$this->assertStringContainsString( 'Partial', $html );
		$this->assertStringContainsString( 'status=partial', $html );
	}

	/**
	 * The entry view shows the resume state and the resend action.
	 */
	public function testTheEntryViewShowsTheResumePanelAndTheResendAction(): void {
		$formId  = $this->form();
		$partial = $this->partial( $formId );

		$_GET['entry_id'] = (int) $partial->id;

		ob_start();
		EntryViewPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Saved progress (Save and Resume)', $html );
		$this->assertStringContainsString( 'grace@example.test', $html );
		$this->assertStringContainsString( 'value="relevant_forms_resend_resume"', $html );
		$this->assertStringContainsString( 'Email a new resume link', $html );
	}

	/**
	 * The resend action issues a fresh token, emails the link and records
	 * the send.
	 */
	public function testTheResendActionRegeneratesTheTokenAndEmailsTheLink(): void {
		$this->captureMail();

		$formId  = $this->form();
		$partial = $this->partial( $formId );
		$before  = (string) $partial->resumeTokenHash;

		$target = $this->captureRedirect(
			[ ResumeLinkController::class, 'resend' ],
			'relevant_resume_resend_' . $partial->id,
			[ 'entry_id' => $partial->id ]
		);

		$this->assertStringContainsString( 'resume_notice=sent', $target );

		$after = ( new EntryRepository() )->find( (int) $partial->id );

		$this->assertInstanceOf( Entry::class, $after );
		$this->assertNotSame( $before, (string) $after->resumeTokenHash );
		$this->assertNotNull( $after->resumeEmailSentAt );
		$this->assertCount( 1, $this->sent_mail );
		$this->assertStringContainsString( 'relevant_resume=', (string) $this->sent_mail[0]['message'] );
	}

	/**
	 * Entries that cannot be resumed answer with the not_available notice.
	 */
	public function testTheResendActionRefusesNonPartialEntries(): void {
		$formId = $this->form();

		$entryId = ( new EntryRepository() )->create(
			new Entry(
				null,
				$formId,
				( new FormRepository() )->createVersion( $formId, ( new FormRepository() )->find( $formId )->schema ),
				Entry::STATUS_NEW,
				'',
				'',
				'',
				'',
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				[ 'f_name' => 'Ada' ]
			)
		);

		$target = $this->captureRedirect(
			[ ResumeLinkController::class, 'resend' ],
			'relevant_resume_resend_' . $entryId,
			[ 'entry_id' => $entryId ]
		);

		$this->assertStringContainsString( 'resume_notice=not_available', $target );
	}

	/**
	 * The daily cleanup deletes expired partial entries and their meta.
	 */
	public function testTheCleanupRemovesExpiredPartialEntries(): void {
		$formId = $this->form();

		$expired = $this->partial( $formId, 'expired@example.test', '2020-01-01 00:00:00' );
		$active  = $this->partial( $formId, 'active@example.test', gmdate( 'Y-m-d H:i:s', time() + 86400 ) );

		PartialEntryCleanup::run();

		$repository = new EntryRepository();

		$this->assertNull( $repository->find( (int) $expired->id ) );
		$this->assertInstanceOf( Entry::class, $repository->find( (int) $active->id ) );

		global $wpdb;

		$meta = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'relevant_entry_meta WHERE entry_id = %d', (int) $expired->id )
		);

		$this->assertSame( 0, $meta );
	}

	/**
	 * Create a published form with Save and Resume enabled.
	 *
	 * @param array<string, mixed> $saveResume Save and Resume overrides.
	 */
	private function form( array $saveResume = [] ): int {
		$schema = FormSchema::empty( 'Resumable' )
			->withFields(
				[
					[
						'id'    => 'f_name',
						'type'  => 'text',
						'label' => 'Name',
					],
				]
			)
			->withSaveResume(
				[
					'enabled' => true,
					...$saveResume,
				]
			);

		return ( new FormRepository() )->create( 'Resumable', $schema, 'published' );
	}

	/**
	 * Insert a partial entry directly.
	 *
	 * @param int    $formId    Form ID.
	 * @param string $email     Requested address.
	 * @param string $expiresAt UTC expiry.
	 */
	private function partial( int $formId, string $email = 'grace@example.test', string $expiresAt = '' ): Entry {
		$repository = new EntryRepository();
		$form       = ( new FormRepository() )->find( $formId );

		$this->assertNotNull( $form );

		if ( '' === $expiresAt ) {
			$expiresAt = gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 );
		}

		$id = $repository->create(
			new Entry(
				null,
				$formId,
				( new FormRepository() )->createVersion( $formId, $form->schema ),
				Entry::STATUS_PARTIAL,
				'https://example.test/quote/',
				'',
				'',
				'',
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				[ 'f_name' => 'Ada' ],
				ResumeTokens::hash( str_repeat( 'a', 64 ) ),
				$email,
				$expiresAt,
				2,
				null
			)
		);

		$entry = $repository->find( $id );

		$this->assertInstanceOf( Entry::class, $entry );

		return $entry;
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_POST, $_REQUEST['_wpnonce'], $_GET['form_id'], $_GET['status'], $_GET['entry_id'] );

		parent::tearDown();
	}
}
