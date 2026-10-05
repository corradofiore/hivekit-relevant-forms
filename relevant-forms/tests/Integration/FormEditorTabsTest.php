<?php
/**
 * Form editor tabs integration tests: the editor splits into Form settings
 * and Form fields, with both panels kept in the DOM so a save never drops
 * information from the inactive tab.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Pages\FormEditPage;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FormEditorTabsTest extends TestCase {

	/**
	 * The editor renders the two tabs; Form settings is the default tab.
	 */
	public function testEditorRendersSettingsAndFieldsTabs(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->renderEditor();

		$this->assertStringContainsString( 'nav-tab-wrapper relevant-forms-tabs', $html );
		$this->assertMatchesRegularExpression( '/nav-tab-active[^>]*href="#rf-panel-settings"/', $html );

		$this->assertStringContainsString( 'Form settings', $html );
		$this->assertStringContainsString( 'Form fields', $html );
		$this->assertStringContainsString( 'href="#rf-panel-settings"', $html );
		$this->assertStringContainsString( 'href="#rf-panel-fields"', $html );

		$this->assertLessThan(
			(int) strpos( $html, 'id="rf-panel-fields"' ),
			(int) strpos( $html, 'id="rf-panel-settings"' )
		);
	}

	/**
	 * The editor shows the form's embed shortcode (same code as the list
	 * table) in a blurb above the tabs.
	 */
	public function testEmbedBlurbShowsTheFormsShortcodeBeforeTheTabs(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$formId     = $repository->create( 'Embeddable', FormSchema::empty( 'Embeddable' ), 'published' );

		$_GET['form_id'] = $formId;

		ob_start();
		FormEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'relevant-forms-embed-intro', $html );
		$this->assertStringContainsString( 'class="relevant-forms-embed-intro" id="rf-embed-intro">', $html );
		$this->assertStringContainsString( 'To embed this form on a page, use the code', $html );
		$this->assertStringContainsString( '<code>[relevant_form id="' . $formId . '"]</code>', $html );

		// Above the tabs, right before the save form.
		$this->assertLessThan(
			(int) strpos( $html, 'nav-tab-wrapper relevant-forms-tabs' ),
			(int) strpos( $html, 'relevant-forms-embed-intro' )
		);
		$this->assertLessThan(
			(int) strpos( $html, '<form method="post"' ),
			(int) strpos( $html, 'relevant-forms-embed-intro' )
		);
	}

	/**
	 * A new form has no ID yet: the blurb renders hidden with an empty code
	 * (initFormSave reveals it and fills in the shortcode after the first
	 * AJAX save).
	 */
	public function testEmbedBlurbStaysHiddenForNewForms(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		unset( $_GET['form_id'] );

		ob_start();
		FormEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/class="relevant-forms-embed-intro" id="rf-embed-intro" hidden/', $html );
		$this->assertStringContainsString( '<code></code>', $html );
		$this->assertStringNotContainsString( '[relevant_form id="0"]', $html );
	}
	/**
	 * Both tab panels stay inside the save form so every section is
	 * submitted regardless of which tab is visible.
	 */
	public function testBothPanelsRenderInsideTheSaveForm(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->renderEditor();

		$settings = $this->panel( $html, 'rf-panel-settings' );
		$fields   = $this->panel( $html, 'rf-panel-fields' );

		$this->assertStringContainsString( 'name="form_name"', $settings );
		$this->assertStringContainsString( 'name="form_status"', $settings );
		$this->assertStringContainsString( 'name="confirmation_message"', $settings );
		$this->assertStringContainsString( 'name="notification_emails"', $settings );
		$this->assertStringContainsString( 'name="sender_name"', $settings );
		$this->assertStringContainsString( 'name="sender_email"', $settings );
		$this->assertStringContainsString( 'name="ai_enabled"', $settings );
		$this->assertStringContainsString( 'name="ai_priorities"', $settings );
		$this->assertStringContainsString( 'What needs immediate attention', $settings );
		$this->assertStringContainsString( 'name="ai_privacy_mode"', $settings );
		$this->assertStringContainsString( 'name="ai_spam"', $settings );
		$this->assertStringContainsString( 'Unwanted enquiries', $settings );
		$this->assertStringContainsString( 'id="rf-send-test-email"', $settings );
		$this->assertStringContainsString( 'id="relevant-test-email-to"', $settings );
		$this->assertSame( 6, substr_count( $settings, 'rf-ai-dependent' ) );

		// Row order: privacy directly below the classify toggle; the SMS pair
		// below "What needs immediate attention"; unwanted enquiries last.
		$this->assertLessThan( (int) strpos( $settings, 'name="ai_privacy_mode"' ), (int) strpos( $settings, 'name="ai_enabled"' ) );
		$this->assertLessThan( (int) strpos( $settings, 'name="ai_purpose"' ), (int) strpos( $settings, 'name="ai_privacy_mode"' ) );
		$this->assertLessThan( (int) strpos( $settings, 'id="relevant-ai-sms-alert"' ), (int) strpos( $settings, 'name="ai_priorities"' ) );
		$this->assertLessThan( (int) strpos( $settings, 'name="ai_spam"' ), (int) strpos( $settings, 'id="relevant-ai-sms-phone"' ) );

		// The Priority SMS row is checkbox-only: no heading label.
		$this->assertStringNotContainsString( '>Priority SMS</th>', $settings );
		$this->assertStringContainsString( 'relevant-forms-ai-panel', $settings );
		$this->assertStringContainsString( 'AI evaluates meaning, relevance, intent and urgency.', $settings );
		$this->assertStringNotContainsString( 'id="rf-fields-list"', $settings );

		$this->assertStringContainsString( 'id="rf-fields-list"', $fields );
		$this->assertStringContainsString( 'id="rf-fields-editors"', $fields );
		$this->assertStringContainsString( 'id="rf-add-field"', $fields );
		$this->assertStringNotContainsString( 'name="form_name"', $fields );
	}

	/**
	 * The save button sits below the field list (left column) and at the
	 * bottom of the editor column, plus one on the settings panel.
	 */
	public function testSaveButtonsRenderInBothFieldsColumns(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html     = $this->renderEditor();
		$settings = $this->panel( $html, 'rf-panel-settings' );
		$fields   = $this->panel( $html, 'rf-panel-fields' );

		// Exactly three save buttons: settings panel, fields list column,
		// fields editor column.
		$this->assertSame( 3, substr_count( $html, 'rf-save--' ) );
		$this->assertSame( 1, substr_count( $settings, 'rf-save--settings' ) );
		$this->assertSame( 1, substr_count( $fields, 'rf-save--list' ) );
		$this->assertSame( 1, substr_count( $fields, 'rf-save--editor' ) );

		$this->assertSame( 1, substr_count( $settings, '>Save Form</button>' ) );
		$this->assertSame( 2, substr_count( $fields, '>Save Form</button>' ) );

		// Left column: below the field list, before the editor column.
		$list      = (int) strpos( $fields, 'id="rf-fields-list"' );
		$listSave  = (int) strpos( $fields, 'rf-save--list' );
		$editorCol = (int) strpos( $fields, 'rf-fields-column--editor' );

		$this->assertLessThan( $listSave, $list );
		$this->assertLessThan( $editorCol, $listSave );

		// Right column: below the visibility block.
		$visibility = (int) strpos( $fields, 'id="rf-fields-visibility"' );
		$columnSave = (int) strpos( $fields, 'rf-save--editor' );

		$this->assertLessThan( $columnSave, $visibility );
		$this->assertGreaterThan( $editorCol, $columnSave );
	}

	/**
	 * The recipients caption lists every site administrator email.
	 */
	public function testRecipientsCaptionListsTheAdministratorEmails(): void {
		$current = self::factory()->user->create(
			[
				'role'       => 'administrator',
				'user_email' => 'current@example.com',
			]
		);
		wp_set_current_user( $current );

		self::factory()->user->create(
			[
				'role'       => 'administrator',
				'user_login' => 'aa_admin',
				'user_email' => 'alpha@example.com',
			]
		);
		self::factory()->user->create(
			[
				'role'       => 'administrator',
				'user_login' => 'zz_admin',
				'user_email' => 'omega@example.com',
			]
		);

		$html = $this->renderEditor();

		$this->assertSame(
			1,
			preg_match( '/site administrator email \(([^)]+)\)/', $html, $matches )
		);

		$listed = array_map( 'trim', explode( ',', $matches[1] ) );

		$this->assertGreaterThanOrEqual( 3, count( $listed ) );
		$this->assertContains( 'current@example.com', $listed );
		$this->assertContains( 'alpha@example.com', $listed );
		$this->assertContains( 'omega@example.com', $listed );
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
	 * Render the editor for a created form.
	 */
	private function renderEditor(): string {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Tabbed' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
			]
		);

		$_GET['form_id'] = $repository->create( 'Tabbed', $schema, 'published' );

		ob_start();
		FormEditPage::render();

		return (string) ob_get_clean();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_GET['form_id'] );

		parent::tearDown();
	}
}
