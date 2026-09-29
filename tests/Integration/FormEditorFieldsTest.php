<?php
/**
 * Form fields editor layout integration tests: draggable list on the left,
 * the selected field's editor on the right.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Assets\AdminAssets;
use RelevantForms\Admin\Controllers\FormSaveController;
use RelevantForms\Admin\Pages\FormEditPage;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Tests\Integration\Support\CapturesRedirects;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FormEditorFieldsTest extends TestCase {

	use CapturesRedirects;

	/**
	 * The builder renders two columns with the add control above the list.
	 */
	public function testTheBuilderRendersTwoColumns(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );

		$this->assertStringContainsString( 'rf-fields-columns', $html );
		$this->assertStringContainsString( 'rf-fields-column--list', $html );
		$this->assertStringContainsString( 'rf-fields-column--editor', $html );
		$this->assertStringContainsString( 'id="rf-fields-list"', $html );
		$this->assertStringContainsString( 'id="rf-fields-editors"', $html );

		// "Add field" sits above the list, inside the left column.
		$add  = (int) strpos( $html, 'id="rf-add-field"' );
		$list = (int) strpos( $html, 'id="rf-fields-list"' );

		$this->assertGreaterThan( 0, $add );
		$this->assertLessThan( $list, $add );
	}

	/**
	 * The page header names the form being edited; the fields header is the
	 * anchor JS renames to the selected field.
	 */
	public function testTheHeadersNameTheFormAndTheFieldsAnchor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );

		$this->assertStringContainsString( '<h1>Edit Form: Contact</h1>', $html );
		$this->assertStringContainsString( '<h2 id="rf-fields-title">Fields</h2>', $html );

		// The header sits in the right column, above the field editor.
		$this->assertMatchesRegularExpression(
			'/rf-fields-column--editor.*?<h2 id="rf-fields-title">Fields<\/h2>.*?id="rf-fields-editors"/s',
			$html
		);

		// A new form has no name yet.
		unset( $_GET['form_id'] );

		ob_start();
		FormEditPage::render();
		$new_html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h1>Add New Form</h1>', $new_html );
	}

	/**
	 * Every field renders a draggable list item (name + type) and a matching
	 * editor panel that carries its inputs.
	 */
	public function testEveryFieldRendersAListItemAndAnEditor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );

		// The first field is pre-selected.
		$this->assertMatchesRegularExpression(
			'/class="rf-field-item rf-field-item--active"[^>]*data-for="f_name"[^>]*draggable="true"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/class="rf-field-item"[^>]*data-for="f_email"[^>]*draggable="true"/',
			$html
		);

		// Items show the field name and its type.
		$this->assertStringContainsString( '<span class="rf-field-item__name">Name</span>', $html );
		$this->assertStringContainsString( '<span class="rf-field-item__name">Email</span>', $html );

		// Scope to the list: the type templates repeat the same markup.
		$list = $this->listRegion( $html );
		$this->assertSame( 2, substr_count( $list, 'rf-field-item__type' ) );

		// Editor panels mirror the list via data-for and keep every input.
		$this->assertStringContainsString( 'class="rf-field-editor" data-for="f_name"', $html );
		$this->assertStringContainsString( 'class="rf-field-editor" data-for="f_email"', $html );
		$this->assertStringContainsString( 'name="relevant_fields[f_name][id]"', $html );
		$this->assertStringContainsString( 'name="relevant_fields[f_name][label]"', $html );
		$this->assertStringContainsString( 'name="relevant_fields[f_email][id]"', $html );
		$this->assertStringContainsString( 'name="relevant_fields[f_email][label]"', $html );
	}

	/**
	 * The editor panel rows use the same nested form-table layout as the
	 * settings panels: headers in the left column, fields in the right.
	 */
	public function testTheEditorRowsUseTheSettingsTwoColumnLayout(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );

		// Nested two-column table inside the editor panel, like the settings.
		$this->assertStringContainsString( '<table class="form-table" role="presentation">', $html );
		$this->assertStringContainsString( '<th scope="row"><label for="rf-field-f_name-label">Field name</label></th>', $html );
		$this->assertStringContainsString( 'id="rf-field-f_name-label" name="relevant_fields[f_name][label]"', $html );
		$this->assertStringContainsString( '<th scope="row"><label for="rf-field-f_name-required">Required</label></th>', $html );
		$this->assertStringContainsString( 'id="rf-field-f_name-required" name="relevant_fields[f_name][required]"', $html );
		$this->assertStringContainsString( '<th scope="row"><label for="rf-field-f_name-placeholder">Placeholder</label></th>', $html );
		$this->assertStringContainsString( '<th scope="row"><label for="rf-field-f_name-help-text">Help text</label></th>', $html );
		$this->assertStringContainsString( '<th scope="row"><label for="rf-field-f_email-default">Default value</label></th>', $html );
	}

	/**
	 * Each panel prepends the field type as plain text; the preview lives in
	 * its own panel below the editor, under a "Preview" header.
	 */
	public function testTheEditorPanelShowsTheTypeAndThePreviewIsSplitOut(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );

		// The field type is the first settings row: read-only text, no input.
		$this->assertStringContainsString( '<th scope="row">Field type</th>', $html );
		$this->assertMatchesRegularExpression(
			'/<tr>\s*<th scope="row">Field type<\/th>\s*<td>Single-line text<\/td>\s*<\/tr>/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/<tr>\s*<th scope="row">Field type<\/th>\s*<td>Email<\/td>\s*<\/tr>/',
			$html
		);
		$this->assertStringNotContainsString( 'rf-field-editor__type', $html );

		// The preview is a separate panel with a header like the Fields one,
		// kept in the right column under the field editor.
		$this->assertStringContainsString( '<h2 id="rf-fields-preview-title">Preview</h2>', $html );
		$this->assertStringContainsString( '<div id="rf-fields-preview" class="rf-fields-preview">', $html );
		$this->assertMatchesRegularExpression(
			'/rf-fields-column--editor.*?id="rf-fields-editors".*?id="rf-fields-preview-title".*?<div id="rf-fields-preview"/s',
			$html
		);
		$this->assertStringContainsString( 'class="rf-field-preview" data-for="f_name"', $html );
		$this->assertStringContainsString( 'class="rf-field-preview" data-for="f_email"', $html );

		// The old in-panel sample block and its title are gone.
		$this->assertStringNotContainsString( 'rf-field-editor__sample', $html );
		$this->assertStringNotContainsString( '>Sample</p>', $html );

		// Preview rendering uses the front-end renderer.
		$this->assertStringContainsString( 'relevant-forms-field relevant-forms-field--text', $html );
		$this->assertStringContainsString( 'relevant-forms-field relevant-forms-field--email', $html );
		$this->assertStringContainsString( 'id="relevant-field-' . $formId . '-f_name"', $html );

		// Preview controls have no form owner: browser validation (required)
		// and submission must skip them, or the save form cannot submit.
		$this->assertStringContainsString( 'form="relevant-forms-preview"', $html );
		$this->assertMatchesRegularExpression(
			'/<input form="relevant-forms-preview"[^>]*name="relevant_field_f_name"/',
			$html
		);
	}

	/**
	 * The sample keeps a live-update contract: the editor exposes the sample
	 * control id, and the list marks required fields with an asterisk the
	 * JS toggles while editing.
	 */
	public function testTheSampleExposesItsControlIdAndTheRequiredMarker(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Marker' )->withFields(
			[
				[
					'id'       => 'f_req',
					'type'     => 'text',
					'label'    => 'Required field',
					'required' => true,
				],
				[
					'id'    => 'f_opt',
					'type'  => 'text',
					'label' => 'Optional field',
				],
			]
		);
		$formId     = $repository->create( 'Marker', $schema, 'draft' );

		$html = $this->renderEditor( $formId );
		$list = $this->listRegion( $html );

		$reqStart = (int) strpos( $list, 'data-for="f_req"' );
		$optStart = (int) strpos( $list, 'data-for="f_opt"' );

		$requiredItem = substr( $list, $reqStart, $optStart - $reqStart );
		$optionalItem = substr( $list, $optStart );

		// Required: visible asterisk next to the name; optional: hidden.
		$this->assertStringContainsString( '<span class="rf-field-item__required" aria-hidden="true">*</span>', $requiredItem );
		$this->assertStringNotContainsString( 'rf-field-item__required" hidden', $requiredItem );
		$this->assertStringContainsString( '<span class="rf-field-item__required" hidden aria-hidden="true">*</span>', $optionalItem );
		$this->assertStringNotContainsString( '<span class="rf-field-item__required" aria-hidden="true">*</span>', $optionalItem );

		// The JS finds the sample label, control and help text by this id.
		$this->assertStringContainsString( 'data-control-id="relevant-field-' . $formId . '-f_req"', $html );
		$this->assertStringContainsString( 'data-control-id="relevant-field-' . $formId . '-f_opt"', $html );

		// The sample label mirrors the front-end renderer: text + asterisk.
		$this->assertStringContainsString(
			'<label for="relevant-field-' . $formId . '-f_req">Required field <span class="relevant-forms-required">*</span></label>',
			$html
		);
		$this->assertStringContainsString(
			'<label for="relevant-field-' . $formId . '-f_opt">Optional field</label>',
			$html
		);
	}

	/**
	 * Hidden fields show a note instead of an invisible sample.
	 */
	public function testHiddenFieldsShowANoteInsteadOfASample(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Hidden' )->withFields(
			[
				[
					'id'    => 'f_token',
					'type'  => 'hidden',
					'label' => 'Token',
				],
			]
		);
		$formId     = $repository->create( 'Hidden', $schema, 'draft' );

		$html = $this->renderEditor( $formId );

		$this->assertStringContainsString( 'Hidden fields are not visible on the front end.', $html );
		$this->assertStringNotContainsString( 'relevant-forms-field--hidden', $html );
	}

	/**
	 * The front-end stylesheet is enqueued on the editor page only, so the
	 * samples look like the real front-end rendering.
	 */
	public function testThePreviewStylesheetLoadsOnTheEditorPageOnly(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		AdminAssets::enqueue( 'relevant-forms_page_relevant-entry' );
		$this->assertFalse( wp_style_is( 'relevant-forms-preview', 'enqueued' ) );

		AdminAssets::enqueue( 'relevant-forms_page_relevant-form-edit' );
		$this->assertTrue( wp_style_is( 'relevant-forms-preview', 'enqueued' ) );
	}

	/**
	 * Each list item has a second row with the Edit, Duplicate and Delete
	 * actions.
	 */
	public function testEachItemShowsEditDuplicateAndDeleteActions(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );
		$list = $this->listRegion( $html );

		$this->assertSame( 2, substr_count( $list, 'rf-field-item__actions' ) );
		$this->assertSame( 2, substr_count( $list, 'class="button-link rf-edit">Edit</button>' ) );
		$this->assertSame( 2, substr_count( $list, 'class="button-link rf-duplicate">Duplicate</button>' ) );
		$this->assertSame( 2, substr_count( $list, 'class="button-link rf-remove">Delete</button>' ) );

		// The handle is a sibling of the content column (it spans both rows),
		// and the actions live in that column next to the field name.
		$this->assertMatchesRegularExpression(
			'/rf-field-item__handle"[^>]*><\/span>\s*<div class="rf-field-item__content">/',
			$list
		);
		$this->assertSame( 2, substr_count( $list, 'class="rf-field-item__content"' ) );
	}

	/**
	 * Text fields expose a configurable maximum length; other types do not,
	 * and the template ships the type default.
	 */
	public function testTextFieldsExposeAConfigurableMaximumLength(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );

		// The text panel shows the row with the default limit (legacy fields
		// without the key fall back to the type default) and a compact input.
		$this->assertMatchesRegularExpression(
			'/name="relevant_fields\[f_name\]\[max_length\]" value="500"/',
			$html
		);
		$this->assertStringContainsString( '<th scope="row"><label for="rf-field-f_name-max-length">Maximum characters</label></th>', $html );
		$this->assertStringContainsString( 'class="rf-input-max-length" id="rf-field-f_name-max-length"', $html );

		// The row sits directly below "Required", with the other validation
		// options, ahead of Placeholder.
		$requiredPos    = strpos( $html, 'rf-field-f_name-required' );
		$maxLengthPos   = strpos( $html, 'rf-field-f_name-max-length' );
		$placeholderPos = strpos( $html, 'rf-field-f_name-placeholder' );
		$this->assertNotFalse( $requiredPos );
		$this->assertNotFalse( $maxLengthPos );
		$this->assertNotFalse( $placeholderPos );
		$this->assertTrue( $requiredPos < $maxLengthPos && $maxLengthPos < $placeholderPos );

		// Other field types do not get the row.
		$this->assertStringNotContainsString( 'relevant_fields[f_email][max_length]', $html );

		// Fresh text fields from the template start at the type default.
		$this->assertStringContainsString( 'name="relevant_fields[{ID}][max_length]" value="500"', $html );
	}

	/**
	 * File upload fields have no default value: their editor skips the row
	 * and shows the upload policy options instead.
	 */
	public function testFileUploadEditorsSkipTheDefaultValueRow(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Uploads' )->withFields(
			[
				[
					'id'    => 'f_notes',
					'type'  => 'text',
					'label' => 'Notes',
				],
				[
					'id'    => 'f_resume',
					'type'  => 'file',
					'label' => 'Resume',
				],
			]
		);

		$html = $this->renderEditor( $repository->create( 'Uploads', $schema, 'published' ) );

		// The text field keeps the row; the file field does not render it.
		$this->assertStringContainsString( '<th scope="row"><label for="rf-field-f_notes-default">Default value</label></th>', $html );
		$this->assertStringNotContainsString( 'rf-field-f_resume-default', $html );
		$this->assertStringNotContainsString( 'relevant_fields[f_resume][default_value]', $html );

		// The upload policy options still render.
		$this->assertStringContainsString( 'rf-field-f_resume-max-size', $html );
		$this->assertStringContainsString( 'rf-field-f_resume-scan', $html );
	}

	/**
	 * Payment amount rows and priced choices name the form currency, so the
	 * editor does not hide which unit the numbers use.
	 */
	public function testPaymentEditorsShowTheFormCurrency(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Checkout' )->withCurrency( 'EUR' )->withFields(
			[
				[
					'id'    => 'f_deposit',
					'type'  => 'payment-single',
					'label' => 'Deposit',
				],
				[
					'id'    => 'f_merch',
					'type'  => 'payment-select',
					'label' => 'Merch',
				],
			]
		);

		$html = $this->renderEditor( $repository->create( 'Checkout', $schema, 'published' ) );

		$this->assertStringContainsString( 'Amount (EUR)', $html );
		$this->assertStringContainsString( 'Minimum amount (EUR)', $html );
		$this->assertStringContainsString( 'Maximum amount (EUR)', $html );
		$this->assertStringContainsString( 'Prices are in EUR.', $html );

		// Samples render in monospace (code) for readability.
		$this->assertStringContainsString( '(<code>|</code>), e.g. <code>T-shirt|25.00</code>.', $html );
	}

	/**
	 * Regular choice captions show the pipe and the sample rows in
	 * monospace too.
	 */
	public function testChoiceCaptionsRenderSamplesInMonospace(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Choices' )->withFields(
			[
				[
					'id'    => 'f_plan',
					'type'  => 'select',
					'label' => 'Plan',
				],
			]
		);

		$html = $this->renderEditor( $repository->create( 'Choices', $schema, 'published' ) );

		$this->assertStringContainsString( '"<code>value|label</code>" format, e.g. <code>not_sure|Not sure</code>', $html );
	}

	/**
	 * Paragraph previews carry the realtime character counter, wired to the
	 * sample control id and showing the field's own limit.
	 */
	public function testPreviewShowsTheCharacterCounterForParagraphFields(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Counter' )->withFields(
			[
				[
					'id'         => 'f_msg',
					'type'       => 'textarea',
					'label'      => 'Message',
					'max_length' => 300,
					'help_text'  => 'Keep it short',
				],
			]
		);
		$formId     = $repository->create( 'Counter', $schema, 'draft' );

		$html = $this->renderEditor( $formId );

		// The preview counter is tied to the sample control, starts at 0 and
		// shows the field's own limit.
		$this->assertStringContainsString(
			'<p class="relevant-forms-char-count" data-rf-count-for="relevant-field-' . $formId . '-f_msg">0/300</p>',
			$html
		);

		// Help text follows the counter (matching the front-end order).
		$this->assertStringContainsString(
			'0/300</p><p class="relevant-forms-help" id="relevant-field-' . $formId . '-f_msg-help">Keep it short</p>',
			$html
		);

		// Fresh paragraph fields from the template start at the type default.
		$this->assertStringContainsString(
			'data-rf-count-for="relevant-field-' . $formId . '-{ID}">0/10000</p>',
			$html
		);
	}

	/**
	 * Section separators: offered in the picker, edited with only a label,
	 * and rendered as a heading + hr in the preview.
	 */
	public function testSectionSeparatorsRenderAndEditAsHeadings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Sections' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
				[
					'id'    => 'f_sec',
					'type'  => 'section',
					'label' => 'Billing details',
				],
			]
		);
		$formId     = $repository->create( 'Sections', $schema, 'draft' );

		$html = $this->renderEditor( $formId );

		// The type is offered in the add-field picker.
		$this->assertStringContainsString( '<option value="section">Section separator</option>', $html );

		// The editor shows a label row only, captioned as the section header.
		$this->assertStringContainsString( '<label for="rf-field-f_sec-label">Section header</label>', $html );
		$this->assertStringContainsString( 'name="relevant_fields[f_sec][label]"', $html );
		$this->assertStringNotContainsString( 'name="relevant_fields[f_sec][required]"', $html );
		$this->assertStringNotContainsString( 'name="relevant_fields[f_sec][placeholder]"', $html );
		$this->assertStringNotContainsString( 'name="relevant_fields[f_sec][help_text]"', $html );
		$this->assertStringNotContainsString( 'name="relevant_fields[f_sec][default_value]"', $html );

		// The preview renders the separator with its heading.
		$this->assertStringContainsString( 'relevant-forms-field--section', $html );
		$this->assertStringContainsString( '<h3 class="relevant-forms-section-title">Billing details</h3>', $html );
		$this->assertStringContainsString( '<hr class="relevant-forms-section-separator">', $html );

		// A fresh section starts with a default header in its template only.
		$section_start    = (int) strpos( $html, '<template id="rf-field-template-section">' );
		$section_template = substr( $html, $section_start );
		$section_template = substr( $section_template, 0, (int) strpos( $section_template, '</template>' ) );

		$this->assertStringContainsString( 'value="A New Section"', $section_template );
		$this->assertStringContainsString( '<h3 class="relevant-forms-section-title">A New Section</h3>', $section_template );
		$this->assertStringNotContainsString( 'A New Section', substr( $html, 0, $section_start ) );
	}

	/**
	 * The list region of the rendered editor (before the editors column).
	 *
	 * @param string $html Rendered editor page.
	 */
	private function listRegion( string $html ): string {
		$list = substr( $html, (int) strpos( $html, 'id="rf-fields-list"' ) );

		return substr( $list, 0, (int) strpos( $list, 'id="rf-fields-editors"' ) );
	}

	/**
	 * Fields without a label fall back to "Untitled field" in the list.
	 */
	public function testUnlabelledFieldsShowTheUntitledFallback(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm( false );

		$html = $this->renderEditor( $formId );

		$this->assertStringContainsString( '<span class="rf-field-item__name">Untitled field</span>', $html );
	}

	/**
	 * Each field type ships a template with ALL fragments (item, editor,
	 * preview and visibility) so the JS can add a field to every container.
	 */
	public function testFieldTemplatesCarryBothFragments(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );

		// Each type ships an item, an editor, a preview AND a visibility
		// fragment, so the JS can add a field to all four containers.
		$this->assertMatchesRegularExpression(
			'/<template id="rf-field-template-text">.*?class="rf-field-item".*?class="rf-field-editor".*?class="rf-field-preview".*?class="rf-field-visibility".*?<\/template>/s',
			$html
		);
	}

	/**
	 * An empty form shows the select-a-field hint in the right column.
	 */
	public function testEmptyFormShowsTheHint(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$formId     = $repository->create( 'No fields', FormSchema::empty( 'No fields' ), 'draft' );

		$html = $this->renderEditor( $formId );

		$this->assertStringContainsString( 'id="rf-fields-empty"', $html );
		$this->assertStringContainsString( 'Select a field to edit it, or add a new one.', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="rf-fields-empty"[^>]*hidden/', $html );
	}

	/**
	 * Render the editor for a form.
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
	 * Create a published form; with $labelled, fields carry names.
	 *
	 * @param bool $labelled Whether the fields have labels.
	 */
	private function makeForm( bool $labelled = true ): int {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Contact' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => $labelled ? 'Name' : '',
				],
				[
					'id'    => 'f_email',
					'type'  => 'email',
					'label' => $labelled ? 'Email' : '',
				],
			]
		);

		return $repository->create( 'Contact', $schema, 'published' );
	}

	/**
	 * The entry preview editor exposes heading, notice and style controls,
	 * hides input-only rows and renders a review sample.
	 */
	public function testEntryPreviewsRenderNoticeAndStyleControls(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Preview' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
				[
					'id'            => 'f_preview',
					'type'          => 'entry-preview',
					'label'         => '',
					'notice_enable' => true,
					'notice'        => 'Check your answers',
					'style'         => 'table',
				],
			]
		);
		$formId     = $repository->create( 'Preview', $schema, 'draft' );

		$html = $this->renderEditor( $formId );

		// Palette entry with the dedicated controls.
		$this->assertStringContainsString( '<option value="entry-preview">Entry preview</option>', $html );
		$this->assertStringContainsString( '<label for="rf-field-f_preview-label">Heading (optional)</label>', $html );
		$this->assertStringContainsString( 'name="relevant_fields[f_preview][notice_enable]"', $html );
		$this->assertStringContainsString( 'name="relevant_fields[f_preview][notice]"', $html );
		$this->assertStringContainsString( 'name="relevant_fields[f_preview][style]"', $html );

		// Input-only rows stay out of the preview editor.
		$this->assertStringNotContainsString( 'name="relevant_fields[f_preview][required]"', $html );
		$this->assertStringNotContainsString( 'name="relevant_fields[f_preview][placeholder]"', $html );
		$this->assertStringNotContainsString( 'name="relevant_fields[f_preview][help_text]"', $html );
		$this->assertStringNotContainsString( 'name="relevant_fields[f_preview][default_value]"', $html );

		// The preview panel renders the review container with the saved style.
		$this->assertStringContainsString( 'relevant-forms-entry-preview--table', $html );
		$this->assertStringContainsString( 'Check your answers', $html );
		$this->assertStringContainsString( '<option value="table" selected=\'selected\'>Table</option>', $html );
	}

	/**
	 * Entry preview settings post through the editor controller and stick.
	 */
	public function testSavingPersistsEntryPreviewConfiguration(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$_POST = [
			'form_id'         => $formId,
			'form_name'       => 'Contact',
			'form_status'     => 'published',
			'relevant_fields' => [
				'f_name'    => [
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
				'f_preview' => [
					'id'            => 'f_preview',
					'type'          => 'entry-preview',
					'label'         => '',
					'notice_enable' => '1',
					'notice'        => "Check your answers\nbefore sending",
					'style'         => 'compact',
				],
			],
		];

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_form_save' );

		$url = $this->saveAndCaptureRedirect();

		$this->assertStringContainsString( 'saved=1', $url );

		$fields = ( new FormRepository() )->find( $formId )->schema->fields();

		$this->assertSame( 'entry-preview', $fields[1]['type'] );
		$this->assertTrue( $fields[1]['notice_enable'] );
		$this->assertSame( "Check your answers\nbefore sending", $fields[1]['notice'] );
		$this->assertSame( 'compact', $fields[1]['style'] );
	}

	/**
	 * Content blocks edit a heading and an HTML body and render a preview.
	 */
	public function testContentBlocksRenderEditorControlsAndPreview(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Content' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
				[
					'id'      => 'f_content',
					'type'    => 'content',
					'label'   => '',
					'content' => '<h4>Fees</h4><p>Pay <strong>now</strong></p>',
				],
			]
		);
		$formId     = $repository->create( 'Content', $schema, 'draft' );

		$html = $this->renderEditor( $formId );

		// Palette entry with the heading and HTML controls only.
		$this->assertStringContainsString( '<option value="content">Content block</option>', $html );
		$this->assertStringContainsString( '<label for="rf-field-f_content-label">Heading (optional)</label>', $html );
		$this->assertStringContainsString( 'name="relevant_fields[f_content][content]"', $html );
		$this->assertStringNotContainsString( 'name="relevant_fields[f_content][required]"', $html );
		$this->assertStringNotContainsString( 'name="relevant_fields[f_content][placeholder]"', $html );
		$this->assertStringNotContainsString( 'name="relevant_fields[f_content][default_value]"', $html );

		// The preview renders the formatted block.
		$this->assertStringContainsString( 'relevant-forms-field--content', $html );
		$this->assertStringContainsString( '<h4>Fees</h4>', $html );
		$this->assertStringContainsString( '<strong>now</strong>', $html );
	}

	/**
	 * Content block HTML posts through the editor controller, is sanitized
	 * and sticks.
	 */
	public function testSavingPersistsContentBlocks(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$_POST = [
			'form_id'         => $formId,
			'form_name'       => 'Contact',
			'form_status'     => 'published',
			'relevant_fields' => [
				'f_name'    => [
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
				'f_content' => [
					'id'      => 'f_content',
					'type'    => 'content',
					'label'   => '',
					'content' => '<h4>Fees</h4><p>Pay <strong>now</strong></p><script>alert(1)</script>',
				],
			],
		];

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_form_save' );

		$url = $this->saveAndCaptureRedirect();

		$this->assertStringContainsString( 'saved=1', $url );

		$fields = ( new FormRepository() )->find( $formId )->schema->fields();

		$this->assertSame( 'content', $fields[1]['type'] );
		$this->assertStringContainsString( '<h4>Fees</h4><p>Pay <strong>now</strong></p>', $fields[1]['content'] );
		$this->assertStringNotContainsString( '<script', $fields[1]['content'] );
	}

	/**
	 * Custom HTML fields edit raw code; the admin preview shows a safe
	 * placeholder instead of executing it.
	 */
	public function testCustomHtmlFieldsRenderCodeEditorAndSafePlaceholder(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'HTML' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
				[
					'id'   => 'f_html',
					'type' => 'html',
					'code' => '<script>window.x=1;</script>',
				],
			]
		);
		$formId     = $repository->create( 'HTML', $schema, 'draft' );

		$html = $this->renderEditor( $formId );

		$this->assertStringContainsString( '<option value="html">Custom HTML</option>', $html );
		$this->assertStringContainsString( '<label for="rf-field-f_html-label">Name (editor only)</label>', $html );
		$this->assertStringContainsString( 'name="relevant_fields[f_html][code]"', $html );
		$this->assertStringNotContainsString( 'name="relevant_fields[f_html][required]"', $html );
		$this->assertStringNotContainsString( 'name="relevant_fields[f_html][placeholder]"', $html );

		// The preview panel shows a placeholder: raw code never runs here.
		$this->assertStringContainsString( 'it is not previewed here', $html );
		$this->assertStringNotContainsString( '<script>window.x=1;</script>', $html );
	}

	/**
	 * Raw code persists for users who may save unfiltered HTML; it is
	 * sanitized (with an editor warning) for everyone else.
	 */
	public function testSavingCustomHtmlRespectsTheUnfilteredCapability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$_POST = [
			'form_id'         => $formId,
			'form_name'       => 'Contact',
			'form_status'     => 'published',
			'relevant_fields' => [
				'f_name' => [
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
				'f_html' => [
					'id'   => 'f_html',
					'type' => 'html',
					'code' => '<p>Map</p><script>window.x=1;</script>',
				],
			],
		];

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_form_save' );

		$url = $this->saveAndCaptureRedirect();

		$this->assertStringContainsString( 'saved=1', $url );

		$fields = ( new FormRepository() )->find( $formId )->schema->fields();

		$this->assertSame( 'html', $fields[1]['type'] );
		$this->assertSame( '<p>Map</p><script>window.x=1;</script>', $fields[1]['code'] );

		// Drop the capability: the editor warns and the next save strips it.
		// User-level deny: remove_cap() is a no-op for role-granted caps.
		wp_get_current_user()->add_cap( 'unfiltered_html', false );

		$editor = $this->renderEditor( $formId );
		$this->assertStringContainsString( 'Your role cannot save raw code', $editor );

		$_POST['relevant_fields']['f_html']['code'] = '<p>Map</p><script>window.x=2;</script>';
		$_REQUEST['_wpnonce']                       = wp_create_nonce( 'relevant_form_save' );

		$this->saveAndCaptureRedirect();

		$fields = ( new FormRepository() )->find( $formId )->schema->fields();
		$this->assertStringNotContainsString( '<script', $fields[1]['code'] );
		$this->assertStringContainsString( '<p>Map</p>', $fields[1]['code'] );
	}

	/**
	 * Run the save controller and capture its redirect target.
	 */
	private function saveAndCaptureRedirect(): string {
		return $this->captureRedirect( [ FormSaveController::class, 'handle' ] );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_GET['form_id'], $_REQUEST['_wpnonce'] );

		parent::tearDown();
	}
}
