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
		$this->assertStringContainsString( 'name="ai_privacy_mode"', $settings );
		$this->assertStringContainsString( 'name="ai_spam"', $settings );
		$this->assertStringContainsString( 'Unwanted enquiries', $settings );
		$this->assertStringContainsString( 'relevant-forms-ai-panel', $settings );
		$this->assertStringContainsString( 'AI evaluates meaning, relevance, intent and urgency.', $settings );
		$this->assertStringNotContainsString( 'id="rf-fields-list"', $settings );

		$this->assertStringContainsString( 'id="rf-fields-list"', $fields );
		$this->assertStringContainsString( 'id="rf-fields-editors"', $fields );
		$this->assertStringContainsString( 'id="rf-add-field"', $fields );
		$this->assertStringNotContainsString( 'name="form_name"', $fields );
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
