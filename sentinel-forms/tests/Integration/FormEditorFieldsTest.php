<?php
/**
 * Form fields editor layout integration tests: draggable list on the left,
 * the selected field's editor on the right.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Admin\Assets\AdminAssets;
use SentinelForms\Admin\Pages\FormEditPage;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormSchema;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FormEditorFieldsTest extends TestCase {

	/**
	 * The builder renders two columns with the add control above the list.
	 */
	public function testTheBuilderRendersTwoColumns(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );

		$this->assertStringContainsString( 'sf-fields-columns', $html );
		$this->assertStringContainsString( 'sf-fields-column--list', $html );
		$this->assertStringContainsString( 'sf-fields-column--editor', $html );
		$this->assertStringContainsString( 'id="sf-fields-list"', $html );
		$this->assertStringContainsString( 'id="sf-fields-editors"', $html );

		// "Add field" sits above the list, inside the left column.
		$add  = (int) strpos( $html, 'id="sf-add-field"' );
		$list = (int) strpos( $html, 'id="sf-fields-list"' );

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
		$this->assertStringContainsString( '<h2 id="sf-fields-title">Fields</h2>', $html );

		// The header sits in the right column, above the field editor.
		$this->assertMatchesRegularExpression(
			'/sf-fields-column--editor.*?<h2 id="sf-fields-title">Fields<\/h2>.*?id="sf-fields-editors"/s',
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
			'/class="sf-field-item sf-field-item--active"[^>]*data-for="f_name"[^>]*draggable="true"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/class="sf-field-item"[^>]*data-for="f_email"[^>]*draggable="true"/',
			$html
		);

		// Items show the field name and its type.
		$this->assertStringContainsString( '<span class="sf-field-item__name">Name</span>', $html );
		$this->assertStringContainsString( '<span class="sf-field-item__name">Email</span>', $html );

		// Scope to the list: the type templates repeat the same markup.
		$list = $this->listRegion( $html );
		$this->assertSame( 2, substr_count( $list, 'sf-field-item__type' ) );

		// Editor panels mirror the list via data-for and keep every input.
		$this->assertStringContainsString( 'class="sf-field-editor" data-for="f_name"', $html );
		$this->assertStringContainsString( 'class="sf-field-editor" data-for="f_email"', $html );
		$this->assertStringContainsString( 'name="sentinel_fields[f_name][id]"', $html );
		$this->assertStringContainsString( 'name="sentinel_fields[f_name][label]"', $html );
		$this->assertStringContainsString( 'name="sentinel_fields[f_email][id]"', $html );
		$this->assertStringContainsString( 'name="sentinel_fields[f_email][label]"', $html );
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
		$this->assertStringContainsString( '<th scope="row"><label for="sf-field-f_name-label">Label</label></th>', $html );
		$this->assertStringContainsString( 'id="sf-field-f_name-label" name="sentinel_fields[f_name][label]"', $html );
		$this->assertStringContainsString( '<th scope="row"><label for="sf-field-f_name-required">Required</label></th>', $html );
		$this->assertStringContainsString( 'id="sf-field-f_name-required" name="sentinel_fields[f_name][required]"', $html );
		$this->assertStringContainsString( '<th scope="row"><label for="sf-field-f_name-placeholder">Placeholder</label></th>', $html );
		$this->assertStringContainsString( '<th scope="row"><label for="sf-field-f_name-help-text">Help text</label></th>', $html );
		$this->assertStringContainsString( '<th scope="row"><label for="sf-field-f_email-default">Default value</label></th>', $html );
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
		$this->assertStringNotContainsString( 'sf-field-editor__type', $html );

		// The preview is a separate panel with a header like the Fields one,
		// kept in the right column under the field editor.
		$this->assertStringContainsString( '<h2 id="sf-fields-preview-title">Preview</h2>', $html );
		$this->assertStringContainsString( '<div id="sf-fields-preview" class="sf-fields-preview">', $html );
		$this->assertMatchesRegularExpression(
			'/sf-fields-column--editor.*?id="sf-fields-editors".*?id="sf-fields-preview-title".*?<div id="sf-fields-preview"/s',
			$html
		);
		$this->assertStringContainsString( 'class="sf-field-preview" data-for="f_name"', $html );
		$this->assertStringContainsString( 'class="sf-field-preview" data-for="f_email"', $html );

		// The old in-panel sample block and its title are gone.
		$this->assertStringNotContainsString( 'sf-field-editor__sample', $html );
		$this->assertStringNotContainsString( '>Sample</p>', $html );

		// Preview rendering uses the front-end renderer.
		$this->assertStringContainsString( 'sentinel-forms-field sentinel-forms-field--text', $html );
		$this->assertStringContainsString( 'sentinel-forms-field sentinel-forms-field--email', $html );
		$this->assertStringContainsString( 'id="sentinel-field-' . $formId . '-f_name"', $html );

		// Preview controls have no form owner: browser validation (required)
		// and submission must skip them, or the save form cannot submit.
		$this->assertStringContainsString( 'form="sentinel-forms-preview"', $html );
		$this->assertMatchesRegularExpression(
			'/<input form="sentinel-forms-preview"[^>]*name="sentinel_field_f_name"/',
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
		$this->assertStringContainsString( '<span class="sf-field-item__required" aria-hidden="true">*</span>', $requiredItem );
		$this->assertStringNotContainsString( 'sf-field-item__required" hidden', $requiredItem );
		$this->assertStringContainsString( '<span class="sf-field-item__required" hidden aria-hidden="true">*</span>', $optionalItem );
		$this->assertStringNotContainsString( '<span class="sf-field-item__required" aria-hidden="true">*</span>', $optionalItem );

		// The JS finds the sample label, control and help text by this id.
		$this->assertStringContainsString( 'data-control-id="sentinel-field-' . $formId . '-f_req"', $html );
		$this->assertStringContainsString( 'data-control-id="sentinel-field-' . $formId . '-f_opt"', $html );

		// The sample label mirrors the front-end renderer: text + asterisk.
		$this->assertStringContainsString(
			'<label for="sentinel-field-' . $formId . '-f_req">Required field <span class="sentinel-forms-required">*</span></label>',
			$html
		);
		$this->assertStringContainsString(
			'<label for="sentinel-field-' . $formId . '-f_opt">Optional field</label>',
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
		$this->assertStringNotContainsString( 'sentinel-forms-field--hidden', $html );
	}

	/**
	 * The front-end stylesheet is enqueued on the editor page only, so the
	 * samples look like the real front-end rendering.
	 */
	public function testThePreviewStylesheetLoadsOnTheEditorPageOnly(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		AdminAssets::enqueue( 'sentinel-forms_page_sentinel-entry' );
		$this->assertFalse( wp_style_is( 'sentinel-forms-preview', 'enqueued' ) );

		AdminAssets::enqueue( 'sentinel-forms_page_sentinel-form-edit' );
		$this->assertTrue( wp_style_is( 'sentinel-forms-preview', 'enqueued' ) );
	}

	/**
	 * Each list item has a second row with the Duplicate and Delete actions.
	 */
	public function testEachItemShowsDuplicateAndDeleteActions(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );
		$list = $this->listRegion( $html );

		$this->assertSame( 2, substr_count( $list, 'sf-field-item__actions' ) );
		$this->assertSame( 2, substr_count( $list, 'class="button-link sf-duplicate">Duplicate</button>' ) );
		$this->assertSame( 2, substr_count( $list, 'class="button-link sf-remove">Delete</button>' ) );

		// The handle is a sibling of the content column (it spans both rows),
		// and the actions live in that column next to the field name.
		$this->assertMatchesRegularExpression(
			'/sf-field-item__handle"[^>]*><\/span>\s*<div class="sf-field-item__content">/',
			$list
		);
		$this->assertSame( 2, substr_count( $list, 'class="sf-field-item__content"' ) );
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
			'/name="sentinel_fields\[f_name\]\[max_length\]" value="500"/',
			$html
		);
		$this->assertStringContainsString( '<th scope="row"><label for="sf-field-f_name-max-length">Maximum characters</label></th>', $html );
		$this->assertStringContainsString( 'class="sf-input-max-length" id="sf-field-f_name-max-length"', $html );

		// The row sits directly below "Required", with the other validation
		// options, ahead of Placeholder.
		$requiredPos    = strpos( $html, 'sf-field-f_name-required' );
		$maxLengthPos   = strpos( $html, 'sf-field-f_name-max-length' );
		$placeholderPos = strpos( $html, 'sf-field-f_name-placeholder' );
		$this->assertNotFalse( $requiredPos );
		$this->assertNotFalse( $maxLengthPos );
		$this->assertNotFalse( $placeholderPos );
		$this->assertTrue( $requiredPos < $maxLengthPos && $maxLengthPos < $placeholderPos );

		// Other field types do not get the row.
		$this->assertStringNotContainsString( 'sentinel_fields[f_email][max_length]', $html );

		// Fresh text fields from the template start at the type default.
		$this->assertStringContainsString( 'name="sentinel_fields[{ID}][max_length]" value="500"', $html );
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
				],
			]
		);
		$formId     = $repository->create( 'Counter', $schema, 'draft' );

		$html = $this->renderEditor( $formId );

		// The preview counter is tied to the sample control, starts at 0 and
		// shows the field's own limit.
		$this->assertStringContainsString(
			'<p class="sentinel-forms-char-count" data-sf-count-for="sentinel-field-' . $formId . '-f_msg">0/300</p>',
			$html
		);

		// Fresh paragraph fields from the template start at the type default.
		$this->assertStringContainsString(
			'data-sf-count-for="sentinel-field-' . $formId . '-{ID}">0/10000</p>',
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
		$this->assertStringContainsString( '<label for="sf-field-f_sec-label">Section header</label>', $html );
		$this->assertStringContainsString( 'name="sentinel_fields[f_sec][label]"', $html );
		$this->assertStringNotContainsString( 'name="sentinel_fields[f_sec][required]"', $html );
		$this->assertStringNotContainsString( 'name="sentinel_fields[f_sec][placeholder]"', $html );
		$this->assertStringNotContainsString( 'name="sentinel_fields[f_sec][help_text]"', $html );
		$this->assertStringNotContainsString( 'name="sentinel_fields[f_sec][default_value]"', $html );

		// The preview renders the separator with its heading.
		$this->assertStringContainsString( 'sentinel-forms-field--section', $html );
		$this->assertStringContainsString( '<h3 class="sentinel-forms-section-title">Billing details</h3>', $html );
		$this->assertStringContainsString( '<hr class="sentinel-forms-section-separator">', $html );

		// A fresh section starts with a default header in its template only.
		$section_start    = (int) strpos( $html, '<template id="sf-field-template-section">' );
		$section_template = substr( $html, $section_start );
		$section_template = substr( $section_template, 0, (int) strpos( $section_template, '</template>' ) );

		$this->assertStringContainsString( 'value="A New Section"', $section_template );
		$this->assertStringContainsString( '<h3 class="sentinel-forms-section-title">A New Section</h3>', $section_template );
		$this->assertStringNotContainsString( 'A New Section', substr( $html, 0, $section_start ) );
	}

	/**
	 * The list region of the rendered editor (before the editors column).
	 *
	 * @param string $html Rendered editor page.
	 */
	private function listRegion( string $html ): string {
		$list = substr( $html, (int) strpos( $html, 'id="sf-fields-list"' ) );

		return substr( $list, 0, (int) strpos( $list, 'id="sf-fields-editors"' ) );
	}

	/**
	 * Fields without a label fall back to "Untitled field" in the list.
	 */
	public function testUnlabelledFieldsShowTheUntitledFallback(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm( false );

		$html = $this->renderEditor( $formId );

		$this->assertStringContainsString( '<span class="sf-field-item__name">Untitled field</span>', $html );
	}

	/**
	 * Each field type ships a template with BOTH fragments (item + editor)
	 * so the JS can add fields to both columns.
	 */
	public function testFieldTemplatesCarryBothFragments(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );

		// Each type ships an item, an editor AND a preview fragment, so the
		// JS can add a field to all three containers.
		$this->assertMatchesRegularExpression(
			'/<template id="sf-field-template-text">.*?class="sf-field-item".*?class="sf-field-editor".*?class="sf-field-preview".*?<\/template>/s',
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

		$this->assertStringContainsString( 'id="sf-fields-empty"', $html );
		$this->assertStringContainsString( 'Select a field to edit it, or add a new one.', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="sf-fields-empty"[^>]*hidden/', $html );
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
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_GET['form_id'] );

		parent::tearDown();
	}
}
