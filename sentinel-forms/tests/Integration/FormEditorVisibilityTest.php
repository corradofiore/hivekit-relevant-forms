<?php
/**
 * Form editor visibility round-trip integration tests: posted rules are
 * normalized and persisted with the schema.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Admin\Controllers\FormSaveController;
use SentinelForms\Admin\Pages\FormEditPage;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormSchema;
use SentinelForms\Tests\Integration\Support\RedirectedException;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FormEditorVisibilityTest extends TestCase {

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
			'sentinel_fields' => [
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

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'sentinel_form_save' );

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
	 * The editor renders the rules section below the preview, one block per
	 * builder field.
	 */
	public function testEditorRendersTheVisibilitySectionBelowThePreview(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$html = $this->renderEditor( $formId );

		$preview = (int) strpos( $html, 'id="sf-fields-preview"' );
		$title   = (int) strpos( $html, 'id="sf-fields-visibility-title"' );
		$section = (int) strpos( $html, 'id="sf-fields-visibility"' );

		$this->assertGreaterThan( 0, $preview );
		$this->assertGreaterThan( $preview, $title );
		$this->assertGreaterThan( $title, $section );

		// One block per field, keyed like the editors, with the default
		// select posting under the field's visibility path.
		$this->assertStringContainsString( '<div class="sf-field-visibility" data-for="f_name">', $html );
		$this->assertStringContainsString( 'name="sentinel_fields[f_name][visibility][default]"', $html );

		// The form has fields: the section is not hidden.
		$this->assertDoesNotMatchRegularExpression( '/id="sf-fields-visibility"[^>]*hidden/', $html );
	}

	/**
	 * An empty form hides the whole section (and the title).
	 */
	public function testEmptyFormHidesTheVisibilitySection(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$formId     = $repository->create( 'No fields', FormSchema::empty( 'No fields' ), 'draft' );

		$html = $this->renderEditor( $formId );

		$this->assertMatchesRegularExpression( '/id="sf-fields-visibility-title"[^>]*hidden/', $html );
		$this->assertMatchesRegularExpression( '/<div id="sf-fields-visibility" class="sf-fields-visibility" hidden>/', $html );
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
			'/name="sentinel_fields\[f_extra\]\[visibility\]\[default\]"[^>]*>.*?<option value="hidden"\s+selected/s',
			$html
		);

		// Source, operator and value are restored on the saved rule.
		$this->assertMatchesRegularExpression( '/<option value="f_plan"\s+selected=\'selected\'>Plan<\/option>/', $html );
		$this->assertMatchesRegularExpression( '/<option value="is"\s+selected=\'selected\'>is<\/option>/', $html );
		$this->assertStringContainsString( '<select name="sentinel_fields[f_extra][visibility][groups][0][0][value]">', $html );
		$this->assertMatchesRegularExpression( '/<option value="pro"\s+selected=\'selected\'>Pro<\/option>/', $html );

		// The preview announces the flip condition (choices labeled).
		$this->assertStringContainsString( 'sf-field-preview__visibility">Shown when: Plan is Pro</p>', $html );
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
		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
				throw new RedirectedException( $location );
			}
		);

		try {
			FormSaveController::handle();
			$this->fail( 'The controller should redirect.' );
		} catch ( RedirectedException $redirected ) {
			return $redirected->url;
		} finally {
			remove_all_filters( 'wp_redirect' );
		}
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
