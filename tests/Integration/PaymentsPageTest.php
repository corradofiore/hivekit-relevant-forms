<?php
/**
 * Accept payments page integration tests: the Stripe connection panel
 * moved out of the settings tabs onto its own screen.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Pages\PaymentsPage;
use RelevantForms\Cloud\CloudConnection;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class PaymentsPageTest extends TestCase {

	/**
	 * Administrator context.
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * The page renders the Stripe connection panel with its action forms.
	 */
	public function testThePageRendersTheStripeConnectionPanel(): void {
		$this->connectCloud();

		$html = $this->renderPage();

		$this->assertStringContainsString( '<h1>Accept payments</h1>', $html );
		$this->assertStringContainsString( 'Accept payments on your forms in no time through your own Stripe account (Stripe Connect):', $html );
		$this->assertStringContainsString( 'Buyers pay you directly on Stripe&#039;s hosted checkout.', $html );
		$this->assertStringContainsString( 'Card data never touches this site.', $html );
		$this->assertStringContainsString( 'No Stripe account connected.', $html );
		$this->assertStringContainsString( 'Connect Stripe', $html );
		$this->assertStringContainsString( 'a 2% fee on each charge', $html );
		$this->assertStringContainsString( 'in addition to Stripe', $html );

		// The buttons submit the hidden nonced action forms by id.
		$this->assertStringContainsString( 'form="rf-stripe-connect-form"', $html );
		$this->assertStringContainsString( 'form="rf-stripe-refresh-form"', $html );
		$this->assertStringNotContainsString( 'form="rf-stripe-disconnect-form"', $html );
		$this->assertStringContainsString( 'id="rf-stripe-connect-form"', $html );
		$this->assertStringContainsString( 'value="relevant_forms_payments_connect"', $html );
		$this->assertStringContainsString( 'value="relevant_forms_payments_refresh"', $html );
	}

	/**
	 * A connected snapshot shows the account, mode and disconnect action.
	 */
	public function testThePageShowsTheConnectedStripeAccount(): void {
		$this->connectCloud();

		( new CloudConnection() )->updatePayments(
			[
				'connected'       => true,
				'stripe_user_id'  => 'acct_test_9',
				'livemode'        => false,
				'charges_enabled' => true,
				'fee_percent'     => 2.0,
			]
		);

		$html = $this->renderPage();

		$this->assertStringContainsString( 'acct_test_9', $html );
		$this->assertStringContainsString( 'Test mode', $html );
		$this->assertStringContainsString( 'Reconnect Stripe', $html );
		$this->assertStringContainsString( 'Disconnect Stripe', $html );
		$this->assertStringContainsString( 'form="rf-stripe-disconnect-form"', $html );
		$this->assertStringContainsString( 'value="relevant_forms_payments_disconnect"', $html );
	}

	/**
	 * The redirect markers render as admin notices.
	 */
	public function testTheReturnMarkersRenderNotices(): void {
		$this->connectCloud();

		$_GET['payments'] = 'connected';

		$this->assertStringContainsString( 'Stripe account connected. Payments are ready on every form with payment fields.', $this->renderPage() );

		$_GET['payments'] = 'error';

		$this->assertStringContainsString( 'The Stripe authorization was not completed. No changes were made.', $this->renderPage() );

		unset( $_GET['payments'] );
	}

	/**
	 * Without a Cloud connection the page guides back to the settings.
	 */
	public function testThePageGuidesToSettingsWhenTheCloudIsDisconnected(): void {
		$html = $this->renderPage();

		$this->assertStringContainsString( 'page=relevant-settings', $html );
		$this->assertStringContainsString( 'Relevant Cloud settings', $html );
		$this->assertStringNotContainsString( 'rf-stripe-connect-form', $html );
	}

	/**
	 * Subscribers cannot open the page.
	 */
	public function testThePageRequiresTheManageOptionsCapability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->expectException( \WPDieException::class );

		PaymentsPage::render();
	}

	/**
	 * Render the page.
	 */
	private function renderPage(): string {
		ob_start();
		PaymentsPage::render();

		return (string) ob_get_clean();
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
