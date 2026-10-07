<?php
/**
 * Form editor "Form preview" tab integration tests: the tab renders the live
 * form, preview submissions stay client-side, and the preview is refreshed
 * from the stored schema.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\FormPreviewController;
use RelevantForms\Admin\Pages\FormEditPage;
use RelevantForms\Forms\FormRenderer;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Tests\Integration\Support\AjaxRequests;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FormEditorPreviewTest extends TestCase {

	/**
	 * The editor offers a Form preview tab after the Form fields tab, and the
	 * panel renders the live form.
	 */
	public function testTheEditorOffersAFormPreviewTab(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->form( 'Previewable' );
		$html   = $this->renderEditor( $formId );

		$this->assertStringContainsString( 'href="#rf-panel-preview"', $html );
		$this->assertStringContainsString( '>Form preview</a>', $html );

		// Last section: after the fields tab, which follows the settings tab.
		$this->assertLessThan(
			(int) strpos( $html, 'href="#rf-panel-preview"' ),
			(int) strpos( $html, 'href="#rf-panel-fields"' )
		);

		$preview = $this->panel( $html, 'rf-panel-preview' );

		$this->assertStringContainsString( 'id="rf-preview-refresh"', $preview );
		$this->assertStringContainsString( 'id="rf-preview-body"', $preview );
		$this->assertStringContainsString(
			'<form class="relevant-forms relevant-forms--preview" data-form-id="' . $formId . '" data-rf-form-preview="1"',
			$preview
		);
		$this->assertStringContainsString( 'name="relevant_field_f_name"', $preview );
		$this->assertStringContainsString( 'class="relevant-forms-submit wp-element-button"', $preview );
	}

	/**
	 * The preview is not the public renderer: drafts (and archived forms)
	 * preview exactly like published ones.
	 */
	public function testThePreviewRendersFormsThatAreNotPublished(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->form( 'Draft preview', null, 'draft' );

		// The shortcode renders published forms only...
		$this->assertSame( '', FormRenderer::render( $formId ) );

		$preview = $this->panel( $this->renderEditor( $formId ), 'rf-panel-preview' );

		// ...while the editor previews the stored draft.
		$this->assertStringContainsString( 'data-rf-form-preview="1"', $preview );
		$this->assertStringContainsString( 'name="relevant_field_f_name"', $preview );
	}

	/**
	 * Preview markup carries no submission wiring: no upload tokens and no
	 * Save and Resume — the parts that would reach storage even when the
	 * submission itself is intercepted.
	 */
	public function testThePreviewCarriesNoSubmissionWiring(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->form(
			'Save and resume preview',
			null,
			'published',
			[
				'enabled'           => true,
				'label'             => 'Save my progress',
				'drafts_in_browser' => true,
			]
		);

		$preview = $this->panel( $this->renderEditor( $formId ), 'rf-panel-preview' );
		$form    = $this->formMarkup( $preview );

		$this->assertStringNotContainsString( 'data-upload-nonce', $form );
		$this->assertStringNotContainsString( 'data-rf-save-resume-nonce', $form );
		$this->assertStringNotContainsString( 'data-rf-drafts', $form );
		$this->assertStringNotContainsString( 'data-rf-save-resume-row', $form );
		$this->assertStringNotContainsString( 'Save my progress', $preview );

		// The live render keeps all of it.
		$live = FormRenderer::render( $formId );

		$this->assertStringContainsString( 'data-upload-nonce', $live );
		$this->assertStringContainsString( 'data-rf-save-resume-nonce', $live );
		$this->assertStringContainsString( 'Save my progress', $live );
	}

	/**
	 * The panel body renders outside the editor's save form: a form cannot be
	 * nested, and the preview's controls must never join a save request.
	 */
	public function testThePreviewPanelRendersOutsideTheSaveForm(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->renderEditor( $this->form( 'Outside' ) );

		$this->assertLessThan(
			(int) strpos( $html, 'data-rf-form-preview="1"' ),
			(int) strpos( $html, '</form>' )
		);
		$this->assertStringContainsString( 'id="rf-panel-preview"', substr( $html, (int) strpos( $html, '</form>' ) ) );
	}

	/**
	 * Submitting the preview shows the confirmation the visitor would get,
	 * followed by the preview-only note. Redirect confirmations describe the
	 * destination instead of leaving the editor.
	 */
	public function testThePreviewShowsTheConfirmationTheVisitorWouldGet(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$messageId = $this->form(
			'Message preview',
			null,
			'published',
			null,
			[
				'message' => 'Thanks — we got it.',
				'mode'    => 'message',
			]
		);

		$body = $this->panel( $this->renderEditor( $messageId ), 'rf-panel-preview' );

		$this->assertMatchesRegularExpression(
			'/class="relevant-forms-preview__success" data-rf-form-preview-success hidden/',
			$body
		);
		$this->assertStringContainsString( '<p class="relevant-forms-success" role="status">Thanks — we got it.</p>', $body );
		$this->assertStringContainsString( 'Preview only — nothing was submitted', $body );

		$redirectId = $this->form(
			'Redirect preview',
			null,
			'published',
			null,
			[
				'redirect' => 'https://example.com/thanks',
				'mode'     => 'url',
			]
		);

		$redirectBody = $this->panel( $this->renderEditor( $redirectId ), 'rf-panel-preview' );

		$this->assertStringContainsString( 'this form sends visitors to https://example.com/thanks', $redirectBody );
		$this->assertStringContainsString( 'The preview stays on this page.', $redirectBody );
	}

	/**
	 * A form that has never been saved has nothing to preview: the panel asks
	 * for one save instead of rendering an empty form.
	 */
	public function testThePreviewAsksToSaveNewFormsFirst(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		unset( $_GET['form_id'] );

		ob_start();
		FormEditPage::render();
		$html = (string) ob_get_clean();

		$preview = $this->panel( $html, 'rf-panel-preview' );

		$this->assertStringContainsString( 'Save the form once to see the preview.', $preview );
		$this->assertStringNotContainsString( 'data-rf-form-preview="1"', $preview );
	}

	/**
	 * File fields keep their control, with a note that uploads stay disabled.
	 */
	public function testThePreviewDisablesUploadsAndExplainsIt(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->form(
			'Uploads preview',
			[
				[
					'id'    => 'f_cv',
					'type'  => 'file',
					'label' => 'CV',
				],
			]
		);

		$preview = $this->panel( $this->renderEditor( $formId ), 'rf-panel-preview' );

		$this->assertStringContainsString( 'class="relevant-forms-file"', $preview );
		$this->assertStringContainsString( 'File uploads stay disabled in the preview', $preview );
	}

	/**
	 * The refresh endpoint renders the stored form, so edits saved after the
	 * editor loaded show up without reloading the page.
	 */
	public function testThePreviewEndpointRendersTheStoredForm(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$formId     = $this->form( 'Endpoint' );

		// The editor has loaded; the form then gains a second field.
		$repository->update(
			$formId,
			'Endpoint',
			FormSchema::empty( 'Endpoint' )->withFields(
				[
					[
						'id'    => 'f_name',
						'type'  => 'text',
						'label' => 'Name',
					],
					[
						'id'    => 'f_email',
						'type'  => 'email',
						'label' => 'Email',
					],
				]
			),
			'published'
		);

		$_POST['form_id']     = $formId;
		$_REQUEST['_wpnonce'] = wp_create_nonce( FormPreviewController::ACTION );

		$response = AjaxRequests::dispatch( [ FormPreviewController::class, 'render' ] );

		$this->assertTrue( $response['success'] );

		$html = (string) ( $response['data']['html'] ?? '' );

		$this->assertStringContainsString( 'name="relevant_field_f_email"', $html );
		$this->assertStringContainsString( 'data-rf-form-preview="1"', $html );

		// The whole panel body comes back, confirmation block included, so the
		// refreshed preview matches the markup the editor renders on load.
		$this->assertStringContainsString( 'class="relevant-forms-preview__success" data-rf-form-preview-success hidden', $html );
		$this->assertStringContainsString( 'class="relevant-forms-submit wp-element-button"', $html );
	}

	/**
	 * Unknown forms are refused: the editor is told to save first.
	 */
	public function testThePreviewEndpointRejectsUnknownForms(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST['form_id']     = 999999;
		$_REQUEST['_wpnonce'] = wp_create_nonce( FormPreviewController::ACTION );

		$response = AjaxRequests::dispatch( [ FormPreviewController::class, 'render' ] );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'not_found', $response['data']['code'] );
	}

	/**
	 * Only users who may manage the site can render a preview.
	 */
	public function testThePreviewEndpointRequiresTheCapability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$_POST['form_id']     = $this->form( 'Guarded' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( FormPreviewController::ACTION );

		$response = AjaxRequests::dispatch( [ FormPreviewController::class, 'render' ] );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', (string) $response['data']['message'] );
	}

	/**
	 * The public render keeps its own markup: no preview markers and the real
	 * form id in the control ids.
	 */
	public function testTheLiveRenderKeepsItsOwnMarkup(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->form( 'Live' );
		$html   = FormRenderer::render( $formId );

		$this->assertStringContainsString(
			'<form class="relevant-forms" data-form-id="' . $formId . '" data-upload-nonce="',
			$html
		);
		$this->assertStringNotContainsString( 'data-rf-form-preview', $html );
		$this->assertStringContainsString( 'id="relevant-field-' . $formId . '-f_name"', $html );
	}

	/**
	 * Raw author code never runs inside wp-admin: custom HTML blocks are
	 * rendered through wp_kses_post and the panel says what it left out. The
	 * live form still renders the block as authored.
	 */
	public function testThePreviewDoesNotExecuteCustomHtml(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->form(
			'HTML preview',
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
				[
					'id'   => 'f_html',
					'type' => 'html',
					'code' => '<h2>Fees</h2><script>window.x=1;</script>',
				],
			]
		);

		$preview = $this->panel( $this->renderEditor( $formId ), 'rf-panel-preview' );

		$this->assertStringNotContainsString( '<script>window.x=1;</script>', $preview );
		$this->assertStringContainsString( '<h2>Fees</h2>', $preview );
		$this->assertStringContainsString( 'scripts and unsafe markup are not run in the preview', $preview );

		$this->assertStringContainsString( '<script>window.x=1;</script>', FormRenderer::render( $formId ) );
	}

	/**
	 * Create one form with a single text field.
	 *
	 * @param string                                $name         Form name.
	 * @param array<int, array<string, mixed>>|null $fields       Field definitions; one text field when omitted.
	 * @param string                                $status       Form status.
	 * @param array<string, mixed>|null             $saveResume   Save and Resume configuration.
	 * @param array<string, mixed>|null             $confirmation Confirmation settings (message/redirect/mode).
	 */
	private function form(
		string $name,
		?array $fields = null,
		string $status = 'published',
		?array $saveResume = null,
		?array $confirmation = null
	): int {
		$schema = FormSchema::empty( $name )->withFields(
			$fields ?? [
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
			]
		);

		if ( null !== $saveResume ) {
			$schema = $schema->withSaveResume( $saveResume );
		}

		if ( null !== $confirmation ) {
			$schema = $schema->withConfirmation(
				(string) ( $confirmation['message'] ?? '' ),
				(string) ( $confirmation['redirect'] ?? '' ),
				(int) ( $confirmation['page'] ?? 0 ),
				isset( $confirmation['mode'] ) ? (string) $confirmation['mode'] : null
			);
		}

		return ( new FormRepository() )->create( $name, $schema, $status );
	}

	/**
	 * Render the form editor for one form.
	 *
	 * @param int $formId Form id.
	 */
	private function renderEditor( int $formId ): string {
		$_GET['form_id'] = $formId;

		ob_start();
		FormEditPage::render();

		return (string) ob_get_clean();
	}

	/**
	 * Extract one tab panel's markup.
	 *
	 * @param string $html Rendered editor page.
	 * @param string $id   Panel id.
	 */
	private function panel( string $html, string $id ): string {
		$this->assertSame(
			1,
			preg_match( '/<div id="' . $id . '" class="relevant-forms-tabs__panel">(.*?)<\/div><!-- #' . $id . ' -->/s', $html, $matches )
		);

		return $matches[1];
	}

	/**
	 * Extract the preview's form element from its panel markup.
	 *
	 * @param string $html Preview panel markup.
	 */
	private function formMarkup( string $html ): string {
		$this->assertSame(
			1,
			preg_match( '/<form class="relevant-forms relevant-forms--preview".*?<\/form>/s', $html, $matches )
		);

		return $matches[0];
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_GET['form_id'], $_POST['form_id'], $_REQUEST['_wpnonce'] );

		parent::tearDown();
	}
}
