<?php
/**
 * Settings tabs integration tests: the settings screen splits into the
 * Relevant Cloud subscription and file storage panels (the payments
 * configuration moved to the Accept payments page) while every options
 * field stays inside the single options form.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Pages\SettingsPage;
use RelevantForms\Cloud\CloudConnection;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class SettingsTabsTest extends TestCase {

	/**
	 * Administrator context.
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * The screen renders two tabs with the Cloud subscription first; the
	 * payments configuration lives on its own Accept payments page now.
	 */
	public function testTheScreenRendersTwoTabsWithTheCloudPanelFirst(): void {
		update_option( 'relevant_forms_settings', [] );

		$html = $this->renderSettings();

		$this->assertStringContainsString( '<h1>Relevant Cloud</h1>', $html );

		// The blurb sits above the tabs, outside the tab panels.
		$this->assertStringContainsString( 'adding semantic spam analysis, priority SMS alerting, managed secure uploads, and Stripe payments to your forms', $html );
		$this->assertLessThan(
			(int) strpos( $html, 'nav-tab-wrapper' ),
			(int) strpos( $html, 'relevant-forms-cloud-intro' )
		);
		$this->assertLessThan(
			(int) strpos( $html, 'id="rf-panel-cloud"' ),
			(int) strpos( $html, 'relevant-forms-cloud-intro' )
		);

		$this->assertStringContainsString( 'nav-tab-wrapper relevant-forms-tabs', $html );
		$this->assertMatchesRegularExpression( '/nav-tab-active[^>]*href="#rf-panel-cloud"/', $html );

		$this->assertStringContainsString( 'Relevant Cloud subscription', $html );
		$this->assertStringContainsString( 'File storage', $html );

		$this->assertStringContainsString( 'href="#rf-panel-cloud"', $html );
		$this->assertStringContainsString( 'href="#rf-panel-storage"', $html );

		$this->assertLessThan(
			(int) strpos( $html, 'id="rf-panel-storage"' ),
			(int) strpos( $html, 'id="rf-panel-cloud"' )
		);

		// The payments tab, panel and action forms moved to the new page.
		$this->assertStringNotContainsString( 'rf-panel-payments', $html );
		$this->assertStringNotContainsString( 'Payments configuration (Stripe)', $html );
		$this->assertStringNotContainsString( 'rf-stripe-connect-form', $html );
	}

	/**
	 * Every options field stays inside the single options form, and no
	 * form is nested inside it: the action buttons submit forms declared
	 * after the options form via the form attribute.
	 */
	public function testTheOptionsFormKeepsEveryFieldWithoutNestedForms(): void {
		update_option( 'relevant_forms_settings', [ 'cloud_api_key' => 'site-key-1' ] );

		$html = $this->renderSettings();
		$main = $this->optionsForm( $html );

		$this->assertStringNotContainsString( 'name="relevant_forms_settings[cloud_api_key]"', $main );
		$this->assertStringContainsString( 'name="relevant_forms_settings[storage_provider]"', $main );
		$this->assertStringContainsString( 'name="relevant_forms_settings[storage_block_unscanned_downloads]"', $main );
		$this->assertStringContainsString( 'value="Save Changes"', $main );

		$this->assertStringNotContainsString( '<form', $main );

		// The Cloud action button targets its nonced form, declared outside.
		$this->assertStringContainsString( 'form="rf-cloud-action-form"', $main );
		$this->assertStringNotContainsString( 'id="rf-cloud-action-form"', $main );
		$this->assertStringContainsString( 'id="rf-cloud-action-form"', $html );
		$this->assertStringContainsString( 'value="relevant_forms_cloud_connect"', $html );

		// The API key field saves on entry through its own nonced form.
		$this->assertStringContainsString( 'form="rf-cloud-key-form"', $main );
		$this->assertStringContainsString( 'name="cloud_api_key"', $main );
		$this->assertStringContainsString( 'id="rf-cloud-key-form"', $html );
		$this->assertStringContainsString( 'value="relevant_forms_cloud_save_key"', $html );
	}

	/**
	 * Connected screen: the Cloud panel shows the plan, a direct billing
	 * link and the Connection section; the key saves on entry so there is
	 * no Save button on this tab.
	 */
	public function testConnectedCloudPanelTargetsItsActionForms(): void {
		$this->connectCloud();

		$html  = $this->renderSettings();
		$cloud = $this->panel( $html, 'rf-panel-cloud' );

		// The blurb moved above the tabs; the panel starts with the
		// subscription rows.
		$this->assertStringNotContainsString( 'relevant-forms-cloud-intro', $cloud );

		// Subscription first: the sign-in identity clients should remember,
		// the cached plan, and a billing link that pre-fills the sign-in
		// email.
		$this->assertStringContainsString( 'owner@example.com', $cloud );
		$this->assertStringContainsString( '+391234567890', $cloud );
		$this->assertStringContainsString( 'Current plan', $cloud );
		$this->assertStringContainsString( 'href="https://cloud.example.com/billing?email=owner%40example.com"', $cloud );
		$this->assertStringNotContainsString( 'rf-cloud-billing-form', $html );
		$this->assertStringNotContainsString( 'rf-cloud-refresh-form', $html );

		// Then the Connection section: state + disconnect button in the
		// same value column, key field below.
		$this->assertStringContainsString( '<h2>Connection</h2>', $cloud );
		$this->assertStringContainsString( 'Disconnect from Cloud', $cloud );
		$this->assertStringContainsString( 'form="rf-cloud-action-form"', $cloud );
		$this->assertStringContainsString( 'form="rf-cloud-key-form"', $cloud );

		$this->assertLessThan( (int) strpos( $cloud, '<h2>Connection</h2>' ), (int) strpos( $cloud, 'Current plan' ) );
		$this->assertLessThan( (int) strpos( $cloud, 'Cloud API key' ), (int) strpos( $cloud, 'Connection state' ) );

		// No Save button on this tab: the key saves the moment it is entered.
		$this->assertStringNotContainsString( 'Save Changes', $cloud );
	}

	/**
	 * The storage panel keeps its fields and Save button; nothing Stripe
	 * related stays on the settings screen.
	 */
	public function testTheStoragePanelKeepsItsContent(): void {
		$this->connectCloud();

		$html    = $this->renderSettings();
		$storage = $this->panel( $html, 'rf-panel-storage' );

		$this->assertStringContainsString( 'name="relevant_forms_settings[storage_provider]"', $storage );
		$this->assertStringContainsString( 'name="relevant_forms_settings[storage_block_unscanned_downloads]"', $storage );
		$this->assertStringContainsString( 'value="Save Changes"', $storage );
		$this->assertStringNotContainsString( 'cloud_api_key', $storage );
		$this->assertStringNotContainsString( 'Stripe account', $storage );

		$this->assertStringNotContainsString( 'Stripe account', $html );
		$this->assertStringNotContainsString( 'rf-stripe-connect-form', $html );
		$this->assertStringNotContainsString( 'value="relevant_forms_payments_connect"', $html );
	}

	/**
	 * Extract the options form's inner markup (up to its first close —
	 * the form holds no nested forms, so that close ends it).
	 *
	 * @param string $html Rendered settings page.
	 */
	private function optionsForm( string $html ): string {
		$this->assertSame(
			1,
			preg_match( '/<form[^>]*action="options\.php"[^>]*>(.*?)<\/form>/s', $html, $matches )
		);

		return $matches[1];
	}

	/**
	 * Extract one tab panel's markup.
	 *
	 * @param string $html Rendered settings page.
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
	 * Render the settings page.
	 */
	private function renderSettings(): string {
		ob_start();
		SettingsPage::render();

		return (string) ob_get_clean();
	}

	/**
	 * Discounts and complimentary access surface on the Current plan row:
	 * an active coupon gets its own description line, and complimentary
	 * access replaces the plan/status pair (with the end date when set).
	 */
	public function testTheCurrentPlanRowShowsDiscountsAndComplimentaryAccess(): void {
		$this->connectCloud();

		$connection = new CloudConnection();

		$connection->updateSubscription(
			[
				'plan'          => 'cloud',
				'status'        => 'active',
				'active'        => true,
				'discount'      => [
					'coupon'         => [
						'id'                 => 'coupon_1',
						'percent_off'        => 20,
						'duration'           => 'repeating',
						'duration_in_months' => 3,
					],
					'promotion_code' => [ 'code' => 'LAUNCH20' ],
				],
				'complimentary' => [
					'active' => false,
					'until'  => null,
				],
			]
		);

		$cloud = $this->panel( $this->renderSettings(), 'rf-panel-cloud' );

		$this->assertStringContainsString( 'Discount applied: 20% off for 3 month(s).', $cloud );

		$connection->updateSubscription(
			[
				'plan'          => 'complimentary',
				'status'        => 'complimentary',
				'active'        => false,
				'discount'      => null,
				'complimentary' => [
					'active' => true,
					'until'  => '2027-01-15T00:00:00+00:00',
				],
			]
		);

		$cloud = $this->panel( $this->renderSettings(), 'rf-panel-cloud' );

		$this->assertStringContainsString( 'Free (complimentary)', $cloud );
		$this->assertStringContainsString( 'Complimentary access until 2027-01-15.', $cloud );
		$this->assertStringNotContainsString( '2027-01-15T00:00:00', $cloud );
		$this->assertStringNotContainsString( 'Discount applied:', $cloud );
	}

	/**
	 * Bind the site so the Cloud is connected (state ≠ disconnected).
	 */
	private function connectCloud(): void {
		update_option( 'relevant_forms_settings', [ 'cloud_api_key' => 'site-key-1' ] );

		( new CloudConnection() )->connect(
			'site-uuid-1',
			home_url(),
			'Test Site',
			[],
			'https://cloud.example.com/billing',
			[
				'email' => 'owner@example.com',
				'phone' => '+391234567890',
			]
		);
	}
}
