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
		$list = substr( $html, (int) strpos( $html, 'id="sf-fields-list"' ) );
		$list = substr( $list, 0, (int) strpos( $list, 'id="sf-fields-editors"' ) );
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
	 * Each panel prepends the field type as plain text and ends with a sample
	 * rendering produced by the same renderer as the front end.
	 */
	public function testTheEditorPanelShowsTheTypeAndASample(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );

		// The field type as text (not an input), before the actual fields.
		$this->assertStringContainsString( '<p class="sf-field-editor__type">Single-line text</p>', $html );
		$this->assertStringContainsString( '<p class="sf-field-editor__type">Email</p>', $html );

		// Sample rendering at the bottom of the panel.
		$this->assertStringContainsString( 'sf-field-editor__sample', $html );
		$this->assertStringContainsString( '>Sample</p>', $html );
		$this->assertStringContainsString( 'sentinel-forms-field sentinel-forms-field--text', $html );
		$this->assertStringContainsString( 'sentinel-forms-field sentinel-forms-field--email', $html );
		$this->assertStringContainsString( 'id="sentinel-field-' . $formId . '-f_name"', $html );
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

		AdminAssets::enqueue( 'sentinel-forms_page_sentinel-form-new' );
		$this->assertTrue( wp_style_is( 'sentinel-forms-preview', 'enqueued' ) );
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

		$this->assertMatchesRegularExpression(
			'/<template id="sf-field-template-text">.*?class="sf-field-item".*?class="sf-field-editor".*?<\/template>/s',
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
