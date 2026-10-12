<?php
/**
 * Front-end (in-page) submission integration tests: rendered forms post to
 * their own page, validation errors render inline on the themed page with
 * the entered values preserved, and successes redirect back (PRG).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Entries\EntryRepository;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRenderer;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Forms\SubmissionFeedback;
use RelevantForms\Http\SubmissionHandler;
use RelevantForms\Tests\Integration\Support\CapturesRedirects;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FrontendSubmissionTest extends TestCase {

	use CapturesRedirects;

	/**
	 * Simulated POST data is part of the handler contract; reset it between tests.
	 */
	public function setUp(): void {
		parent::setUp();

		SubmissionFeedback::reset();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/relevant-demo/';
		$_SERVER['HTTP_REFERER']   = home_url( '/relevant-demo/' );
	}

	/**
	 * The rendered markup posts to the page itself, never to wp-admin.
	 */
	public function testRenderedFormPostsToItsOwnPage(): void {
		$form = $this->makePublishedForm();

		$html = FormRenderer::renderForm( $form );

		$this->assertStringContainsString( 'action=""', $html );
		$this->assertStringNotContainsString( 'wp-admin', $html );
	}

	/**
	 * Validation errors render on the page with the entered values kept.
	 */
	public function testFrontEndValidationErrorsRenderInline(): void {
		$form = $this->makePublishedForm();

		$_POST = [
			'action'                   => 'relevant_forms_submit',
			'_relevant_form_id'        => $form->id,
			'_relevant_form_nonce'     => wp_create_nonce( 'relevant_form_' . $form->id ),
			'relevant_field_f_name'    => '',
			'relevant_field_f_email'   => 'not-an-email',
			'relevant_field_f_message' => 'Hello there',
		];

		SubmissionHandler::maybeHandleFrontendSubmission();

		// Nothing stored; the request continues so the page can render.
		$this->assertCount( 0, ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] ) );

		// The shortcode re-render picks the stashed feedback up automatically.
		$html = FormRenderer::renderForm( $form );

		$this->assertStringContainsString( 'relevant-forms-error', $html );
		$this->assertStringContainsString( 'This field is required.', $html );
		$this->assertStringContainsString( 'Please enter a valid email address.', $html );
		$this->assertStringContainsString( 'value="not-an-email"', $html );
		$this->assertStringContainsString( 'Hello there', $html );
	}

	/**
	 * A valid front-end submission is stored and redirects back (PRG), even
	 * though the referer equals the current request URL (the front-end flow).
	 */
	public function testFrontEndSuccessStoresTheEntryAndRedirectsBack(): void {
		$form = $this->makePublishedForm();

		$_POST = [
			'action'                   => 'relevant_forms_submit',
			'_relevant_form_id'        => $form->id,
			'_relevant_form_nonce'     => wp_create_nonce( 'relevant_form_' . $form->id ),
			'_wp_http_referer'         => '/relevant-demo/',
			'relevant_field_f_name'    => 'Ada Lovelace',
			'relevant_field_f_email'   => 'ada@example.com',
			'relevant_field_f_message' => 'Boiler is broken.',
		];
		// Real requests populate $_REQUEST from the GET/POST order.
		$_REQUEST['_wp_http_referer'] = '/relevant-demo/';

		$url = $this->frontendSubmitAndCaptureRedirect();

		$this->assertStringContainsString( '/relevant-demo/?relevant_form_success=' . $form->id, $url );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'Ada Lovelace', $entries[0]->meta['f_name'] );
		$this->assertSame( 'ada@example.com', $entries[0]->meta['f_email'] );
	}

	/**
	 * The confirmation replaces the form: the visitor no longer sees (or can
	 * re-submit) the form they just sent, matching WPForms.
	 */
	public function testTheConfirmationReplacesTheFormAfterSuccess(): void {
		$form = $this->makePublishedForm();

		$_GET['relevant_form_success'] = (string) $form->id;

		$html = FormRenderer::renderForm( $form );

		unset( $_GET['relevant_form_success'] );

		$this->assertStringContainsString( 'relevant-forms-success', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'relevant-forms-hp', $html );
	}

	/**
	 * Without the marker the form renders as usual: it only exists on the
	 * redirect that follows the visitor's own submission.
	 */
	public function testTheFormStillRendersWithoutASuccessMarker(): void {
		$form = $this->makePublishedForm();

		$html = FormRenderer::renderForm( $form );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'relevant-forms-success', $html );
	}

	/**
	 * A redirect confirmation sends the visitor elsewhere, so nothing is
	 * replaced even if a marker is present.
	 */
	public function testARedirectConfirmationKeepsTheForm(): void {
		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Redirecting' )
			->withConfirmation( '', 'https://example.com/thanks', 0, 'url' );
		$form       = $repository->find( $repository->create( 'Redirecting', $schema, 'published' ) );

		$_GET['relevant_form_success'] = (string) $form->id;

		$html = FormRenderer::renderForm( $form );

		unset( $_GET['relevant_form_success'] );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'relevant-forms-hp', $html );
	}

	/**
	 * An incomplete payment keeps the form so the visitor can try again.
	 */
	public function testAFailedPaymentKeepsTheForm(): void {
		$form = $this->makePublishedForm();

		$_GET['relevant_payment_failed'] = (string) $form->id;

		$html = FormRenderer::renderForm( $form );

		unset( $_GET['relevant_payment_failed'] );

		$this->assertStringContainsString( 'relevant-forms-payment-notice', $html );
		$this->assertStringContainsString( '<form', $html );
	}

	/**
	 * A triggered honeypot pretends success but stores nothing.
	 */
	public function testFrontEndHoneypotPretendsSuccessWithoutStoring(): void {
		$form = $this->makePublishedForm();

		$_POST = [
			'action'                   => 'relevant_forms_submit',
			'_relevant_form_id'        => $form->id,
			'_relevant_form_nonce'     => wp_create_nonce( 'relevant_form_' . $form->id ),
			'_relevant_hp'             => 'https://spam.example',
			'relevant_field_f_name'    => 'Spambot',
			'relevant_field_f_email'   => 'bot@example.com',
			'relevant_field_f_message' => 'Buy links!',
		];

		$url = $this->frontendSubmitAndCaptureRedirect();

		$this->assertStringContainsString( 'relevant_form_success=' . $form->id, $url );
		$this->assertCount( 0, ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] ) );
	}

	/**
	 * GET requests are never processed by the front-end handler.
	 */
	public function testGetRequestsAreIgnored(): void {
		$form = $this->makePublishedForm();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = [
			'action'            => 'relevant_forms_submit',
			'_relevant_form_id' => $form->id,
		];

		SubmissionHandler::maybeHandleFrontendSubmission();

		$this->assertSame( [], SubmissionFeedback::errors( (int) $form->id ) );
		$this->assertCount( 0, ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] ) );
	}

	/**
	 * A redirect anchored to a page keeps working when the page's slug changes:
	 * the permalink is resolved from the stored ID at submission time.
	 */
	public function testRedirectAnchorFollowsThePageWhenItsSlugChanges(): void {
		$original = (string) get_option( 'permalink_structure' );
		$this->set_permalink_structure( '/%postname%/' );

		try {
			$pageId = self::factory()->post->create(
				[
					'post_type'  => 'page',
					'post_title' => 'Thank You',
					'post_name'  => 'thank-you',
				]
			);

			$form = $this->makeAnchoredForm( $pageId );

			$this->assertSame( get_permalink( $pageId ), $this->submitAnchored( $form ) );

			wp_update_post(
				[
					'ID'        => $pageId,
					'post_name' => 'thank-you-renamed',
				]
			);

			$this->assertStringContainsString( 'thank-you-renamed', (string) get_permalink( $pageId ) );
			$this->assertSame( get_permalink( $pageId ), $this->submitAnchored( $form ) );
		} finally {
			$this->set_permalink_structure( $original );
		}
	}

	/**
	 * The URL mode wins over a stored anchor.
	 */
	public function testRedirectModeUrlWinsOverTheAnchor(): void {
		$pageId = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Thank You',
			]
		);

		$form = $this->makeAnchoredForm( $pageId, 'https://example.com/legacy', 'url' );

		$this->assertSame( 'https://example.com/legacy', $this->submitAnchored( $form ) );
	}

	/**
	 * The message mode ignores stored redirect values.
	 */
	public function testRedirectModeMessageIgnoresStoredRedirects(): void {
		$pageId = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Thank You',
			]
		);

		$form = $this->makeAnchoredForm( $pageId, 'https://example.com/legacy', 'message' );

		$this->assertStringContainsString( 'relevant_form_success=' . $form->id, $this->submitAnchored( $form ) );
	}

	/**
	 * Submit valid data for a form and capture the redirect target.
	 *
	 * @param Form $form Form to submit.
	 */
	private function submitAnchored( Form $form ): string {
		$_POST = [
			'action'                => 'relevant_forms_submit',
			'_relevant_form_id'     => $form->id,
			'_relevant_form_nonce'  => wp_create_nonce( 'relevant_form_' . $form->id ),
			'relevant_field_f_name' => 'Ada Lovelace',
		];

		return $this->frontendSubmitAndCaptureRedirect();
	}

	/**
	 * Create a published form with one field and a redirect configuration.
	 *
	 * @param int         $pageId Redirect anchor page ID.
	 * @param string      $url    Stored redirect URL.
	 * @param string|null $mode   Confirmation mode (inferred when omitted).
	 */
	private function makeAnchoredForm( int $pageId, string $url = '', ?string $mode = null ): Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Anchored' )
			->withFields(
				[
					[
						'id'       => 'f_name',
						'type'     => 'text',
						'label'    => 'Name',
						'required' => true,
					],
				]
			)
			->withConfirmation( '', $url, $pageId, $mode );

		$id = $repository->create( 'Anchored', $schema, 'published' );

		return $repository->find( $id );
	}

	/**
	 * Run the front-end handler and capture the redirect target.
	 */
	private function frontendSubmitAndCaptureRedirect(): string {
		return $this->captureRedirect( [ SubmissionHandler::class, 'maybeHandleFrontendSubmission' ] );
	}

	/**
	 * Create a published form with three fields.
	 */
	private function makePublishedForm(): Form {
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
	 * Create a published form split into two pages by a page break.
	 */
	private function makePaginatedForm(): Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Stepped Request' )->withFields(
			[
				[
					'id'       => 'f_name',
					'type'     => 'text',
					'label'    => 'Name',
					'required' => true,
				],
				[
					'id'    => 'f_break',
					'type'  => 'pagebreak',
					'label' => 'Contact',
				],
				[
					'id'       => 'f_email',
					'type'     => 'email',
					'label'    => 'Email',
					'required' => true,
				],
				[
					'id'            => 'f_preview',
					'type'          => 'entry-preview',
					'label'         => '',
					'notice_enable' => false,
				],
			]
		);

		$id = $repository->create( 'Stepped Request', $schema, 'published' );

		return $repository->find( $id );
	}

	/**
	 * Paginated forms submit in one POST: only real field values are stored,
	 * the page break itself never reaches the entry, and validation errors
	 * render inside their page container.
	 */
	public function testPaginatedSubmissionStoresOnlyFieldValues(): void {
		$form = $this->makePaginatedForm();

		$_POST                        = [
			'action'                 => 'relevant_forms_submit',
			'_relevant_form_id'      => $form->id,
			'_relevant_form_nonce'   => wp_create_nonce( 'relevant_form_' . $form->id ),
			'_wp_http_referer'       => '/relevant-demo/',
			'relevant_field_f_name'  => 'Ada Lovelace',
			'relevant_field_f_email' => 'ada@example.com',
		];
		$_REQUEST['_wp_http_referer'] = '/relevant-demo/';

		$url = $this->frontendSubmitAndCaptureRedirect();

		$this->assertStringContainsString( 'relevant_form_success=' . $form->id, $url );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'Ada Lovelace', $entries[0]->meta['f_name'] );
		$this->assertSame( 'ada@example.com', $entries[0]->meta['f_email'] );
		$this->assertArrayNotHasKey( 'f_break', $entries[0]->meta );
		$this->assertArrayNotHasKey( 'f_preview', $entries[0]->meta );
	}

	/**
	 * A missing value on a later page renders its error inside that page's
	 * container, so the navigation script can reopen it (and readers without
	 * JavaScript see it in the stacked flow).
	 */
	public function testPaginatedValidationErrorRendersInsideItsPage(): void {
		$form = $this->makePaginatedForm();

		$_POST = [
			'action'                 => 'relevant_forms_submit',
			'_relevant_form_id'      => $form->id,
			'_relevant_form_nonce'   => wp_create_nonce( 'relevant_form_' . $form->id ),
			'relevant_field_f_name'  => 'Ada',
			'relevant_field_f_email' => '',
		];

		SubmissionHandler::maybeHandleFrontendSubmission();

		$this->assertCount( 0, ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] ) );

		$html = FormRenderer::renderForm( $form );

		$pageTwo = strpos( $html, 'data-rf-page="2"' );
		$error   = strpos( $html, 'relevant-field-' . $form->id . '-f_email-error' );

		$this->assertNotFalse( $pageTwo );
		$this->assertNotFalse( $error );
		$this->assertGreaterThan( $pageTwo, $error );

		// The preview inside page two echoes the preserved value and keeps
		// the still-empty email row hidden.
		$this->assertStringContainsString( 'relevant-forms-preview-value">Ada</span>', $html );
		$this->assertStringNotContainsString( 'data-rf-preview-row="f_name" hidden', $html );
		$this->assertStringContainsString( 'data-rf-preview-row="f_email" hidden', $html );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		SubmissionFeedback::reset();
		remove_all_filters( 'relevant_forms_redirect' );
		unset( $_REQUEST['_wp_http_referer'] );

		parent::tearDown();
	}
}
