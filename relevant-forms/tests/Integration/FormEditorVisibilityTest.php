<?php
/**
 * Form editor visibility round-trip integration tests: posted rules are
 * normalized and persisted with the schema.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

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
final class FormEditorVisibilityTest extends TestCase {

	use CapturesRedirects;

	/**
	 * Posted visibility rules round-trip through the controller in the
	 * normalized shape; junk groups are dropped.
	 */
	public function testSavingPersistsNormalizedVisibilityRules(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$_POST = [
			'form_id'         => $formId,
			'form_name'       => 'Contact',
			'form_status'     => 'published',
			'relevant_fields' => [
				'f_plan'  => [
					'id'      => 'f_plan',
					'type'    => 'select',
					'label'   => 'Plan',
					'choices' => "Free\nPro",
				],
				'f_extra' => [
					'id'         => 'f_extra',
					'type'       => 'text',
					'label'      => 'Extra',
					'visibility' => [
						'default' => 'hidden',
						'groups'  => [
							[
								[
									'field'    => 'f_plan',
									'operator' => '==',
									'value'    => 'pro',
								],
							],
							[
								[
									'field'    => '',
									'operator' => 'is',
									'value'    => 'x',
								],
							],
						],
					],
				],
			],
		];

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_form_save' );

		$url = $this->handleAndCaptureRedirect();

		$this->assertStringContainsString( 'saved=1', $url );

		$fields = ( new FormRepository() )->find( $formId )->schema->fields();

		// The legacy "==" spelling is canonicalized to "is" and the group
		// without a source field is dropped.
		$this->assertSame(
			[
				'default' => 'hidden',
				'groups'  => [
					[
						[
							'field'    => 'f_plan',
							'operator' => 'is',
							'value'    => 'pro',
						],
					],
				],
			],
			$fields[1]['visibility']
		);
	}

	/**
	 * Rules posted for the first field are never stored — the save-side
	 * half of the always-shown rule; later fields keep theirs.
	 */
	public function testSavingDropsRulesPostedForTheFirstField(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$_POST = [
			'form_id'         => $formId,
			'form_name'       => 'Contact',
			'form_status'     => 'published',
			'relevant_fields' => [
				'f_name'  => [
					'id'         => 'f_name',
					'type'       => 'text',
					'label'      => 'Name',
					'visibility' => [
						'default' => 'hidden',
						'groups'  => [
							[
								[
									'field'    => 'f_email',
									'operator' => 'is',
									'value'    => 'x',
								],
							],
						],
					],
				],
				'f_email' => [
					'id'         => 'f_email',
					'type'       => 'email',
					'label'      => 'Email',
					'visibility' => [
						'default' => 'hidden',
						'groups'  => [
							[
								[
									'field'    => 'f_name',
									'operator' => 'is',
									'value'    => 'y',
								],
							],
						],
					],
				],
			],
		];

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_form_save' );

		$this->assertStringContainsString( 'saved=1', $this->handleAndCaptureRedirect() );

		$fields = ( new FormRepository() )->find( $formId )->schema->fields();

		$this->assertArrayNotHasKey( 'visibility', $fields[0] );
		$this->assertSame( 'f_name', $fields[1]['visibility']['groups'][0][0]['field'] );
	}

	/**
	 * The editor renders the rules section below the preview, one block per
	 * builder field.
	 */
	public function testEditorRendersTheVisibilitySectionBelowThePreview(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );

		$preview = (int) strpos( $html, 'id="rf-fields-preview"' );
		$title   = (int) strpos( $html, 'id="rf-fields-visibility-title"' );
		$section = (int) strpos( $html, 'id="rf-fields-visibility"' );

		$this->assertGreaterThan( 0, $preview );
		$this->assertGreaterThan( $preview, $title );
		$this->assertGreaterThan( $title, $section );

		// One block per field, keyed like the editors. The first (only)
		// field here is always shown: its note is visible and its controls
		// are present but hidden (a reorder flips the states live).
		$this->assertStringContainsString( '<div class="rf-field-visibility" data-for="f_name">', $html );
		$this->assertStringContainsString( 'always shown', $html );

		$blocks = $this->withoutTemplates( $html );

		$this->assertSame( 0, substr_count( $blocks, 'data-first-note hidden' ) );
		$this->assertSame( 1, substr_count( $blocks, 'rf-field-visibility__controls" hidden' ) );

		// The form has fields: the section is not hidden.
		$this->assertDoesNotMatchRegularExpression( '/id="rf-fields-visibility"[^>]*hidden/', $html );
	}

	/**
	 * An empty form hides the whole section (and the title).
	 */
	public function testEmptyFormHidesTheVisibilitySection(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$formId     = $repository->create( 'No fields', FormSchema::empty( 'No fields' ), 'draft' );

		$html = $this->renderEditor( $formId );

		$this->assertMatchesRegularExpression( '/id="rf-fields-visibility-title"[^>]*hidden/', $html );
		$this->assertMatchesRegularExpression( '/<div id="rf-fields-visibility" class="rf-fields-visibility" hidden>/', $html );
	}

	/**
	 * Saved rules render back into the controls and into the preview note.
	 */
	public function testSavedRulesRenderTheirSelections(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeVisibilityForm();

		$html = $this->renderEditor( $formId );

		// The default state comes back selected.
		$this->assertMatchesRegularExpression(
			'/name="relevant_fields\[f_extra\]\[visibility\]\[default\]"[^>]*>.*?<option value="hidden"\s+selected/s',
			$html
		);

		// Source, operator and value are restored on the saved rule.
		$this->assertMatchesRegularExpression( '/<option value="f_plan"\s+selected=\'selected\'>Plan<\/option>/', $html );
		$this->assertMatchesRegularExpression( '/<option value="is"\s+selected=\'selected\'>is<\/option>/', $html );
		$this->assertStringContainsString( '<select name="relevant_fields[f_extra][visibility][groups][0][0][value]">', $html );
		$this->assertMatchesRegularExpression( '/<option value="pro"\s+selected=\'selected\'>Pro<\/option>/', $html );

		// The preview announces the flip condition (choices labeled).
		$this->assertStringContainsString( 'rf-field-preview__visibility">Shown when: Plan is Pro</p>', $html );
	}

	/**
	 * Rules referencing a field that is not above keep the reference with a
	 * "(not above)" option so saving does not silently drop it.
	 */
	public function testOrphanSourcesKeepTheirReference(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Contact' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
				[
					'id'         => 'f_extra',
					'type'       => 'text',
					'label'      => 'Extra',
					'visibility' => [
						'default' => 'shown',
						'groups'  => [
							[
								[
									'field'    => 'f_ghost',
									'operator' => 'is',
									'value'    => 'x',
								],
							],
						],
					],
				],
			]
		);

		$formId = $repository->create( 'Contact', $schema, 'published' );
		$html   = $this->renderEditor( $formId );

		$this->assertStringContainsString( '<option value="f_ghost" selected="selected">f_ghost (not above)</option>', $html );
	}

	/**
	 * Visibility controls start with the second field: the first field
	 * cannot be conditional.
	 */
	public function testTheFirstFieldCannotBeConditional(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeVisibilityForm();

		$html = $this->renderEditor( $formId );

		// The first field's block explains the exemption (note visible,
		// controls hidden); the second field's block is the mirror image.
		$this->assertStringContainsString( 'always shown', $html );
		$this->assertStringContainsString( 'name="relevant_fields[f_plan][visibility][default]"', $html );
		$this->assertStringContainsString( 'name="relevant_fields[f_extra][visibility][default]"', $html );

		$blocks = $this->withoutTemplates( $html );

		$this->assertSame( 1, substr_count( $blocks, 'rf-field-visibility__controls" hidden' ) );
		$this->assertSame( 1, substr_count( $blocks, 'data-first-note hidden' ) );
	}

	/**
	 * Rules stored on the first field are ignored: no controls and no
	 * preview summary (they disappear from the schema on the next save).
	 */
	public function testStoredRulesOnTheFirstFieldAreIgnored(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Contact' )->withFields(
			[
				[
					'id'         => 'f_first',
					'type'       => 'text',
					'label'      => 'First',
					'visibility' => [
						'default' => 'hidden',
						'groups'  => [
							[
								[
									'field'    => 'f_second',
									'operator' => 'is',
									'value'    => 'x',
								],
							],
						],
					],
				],
				[
					'id'    => 'f_second',
					'type'  => 'text',
					'label' => 'Second',
				],
			]
		);

		$formId = $repository->create( 'Contact', $schema, 'published' );

		$html = $this->renderEditor( $formId );

		// The stored rule does not render (the definition was emptied) and
		// no preview summary appears for it.
		$this->assertStringNotContainsString( 'relevant_fields[f_first][visibility][groups][0]', $html );
		$this->assertStringNotContainsString( 'Hidden when:', $html );
	}

	/**
	 * The editor markup without the inert per-type field templates, which
	 * each embed a placeholder visibility block (note hidden, controls on).
	 *
	 * @param string $html Rendered editor.
	 */
	private function withoutTemplates( string $html ): string {
		return (string) preg_replace( '/<template\b.*?<\/template>/s', '', $html );
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
	 * Create a form with a choice source and a conditional text field.
	 */
	private function makeVisibilityForm(): int {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Contact' )->withFields(
			[
				[
					'id'      => 'f_plan',
					'type'    => 'select',
					'label'   => 'Plan',
					'choices' => [ 'Free', 'Pro|pro' ],
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
									'field'    => 'f_plan',
									'operator' => 'is',
									'value'    => 'pro',
								],
							],
						],
					],
				],
			]
		);

		return $repository->create( 'Contact', $schema, 'published' );
	}

	/**
	 * Run the save controller and capture the redirect target.
	 */
	private function handleAndCaptureRedirect(): string {
		return $this->captureRedirect( [ FormSaveController::class, 'handle' ] );
	}

	/**
	 * Create a published form with one field.
	 */
	private function makeForm(): int {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Contact' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
			]
		);

		return $repository->create( 'Contact', $schema, 'published' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_REQUEST['_wpnonce'], $_GET['form_id'] );
		remove_all_filters( 'wp_redirect' );

		parent::tearDown();
	}
}
