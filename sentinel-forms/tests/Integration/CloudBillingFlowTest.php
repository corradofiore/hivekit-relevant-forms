<?php
/**
 * Cloud billing deep-link integration tests (Phase 8; dashboard deep link in D8).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Cloud\CloudConnection;
use SentinelForms\Http\CloudBillingController;
use SentinelForms\Tests\Integration\Support\RedirectedException;

/**
 * Manage billing deep-links into the Sentinel Cloud dashboard with the
 * site context; WordPress never contacts the payment provider itself.
 *
 * @group integration
 */
final class CloudBillingFlowTest extends TestCase {

	/**
	 * Outbound HTTP requests seen during the test (must stay empty).
	 *
	 * @var array<int, string>
	 */
	private array $requests = [];

	/**
	 * Run as an administrator with a connected site.
	 */
	public function setUp(): void {
		parent::setUp();

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$this->requests = [];

		update_option(
			'sentinel_forms_settings',
			[
				'cloud_url'     => 'https://cloud.example.com',
				'cloud_api_key' => 'site-key',
			]
		);

		$connection = new CloudConnection();
		$connection->connect(
			'site-uuid-1',
			'https://a.example',
			'Acme',
			[],
			'https://cloud.example.com/app/sites/site-uuid-1/billing'
		);

		// Tripwire: the deep link must not require any backend call.
		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array {
				unset( $pre, $args );
				$this->requests[] = $url;

				return [
					'response' => [ 'code' => 502 ],
					'body'     => '{"success":false}',
				];
			},
			10,
			3
		);
	}

	/**
	 * Manage billing opens the subscription page in the client dashboard
	 * without any provider call from WordPress.
	 */
	public function testManageBillingDeepLinksToTheDashboard(): void {
		$url = $this->billing();

		$this->assertSame( 'https://cloud.example.com/app/sites/site-uuid-1/billing', $url );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * Disconnected sites are pointed back to the connection settings.
	 */
	public function testDisconnectedSitesCannotOpenBilling(): void {
		$connection = new CloudConnection();
		$connection->disconnect();

		$url = $this->billing();

		$this->assertStringContainsString( 'page=sentinel-settings', $url );
		$this->assertStringContainsString( 'billing=not_connected', $url );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * Account-less connections (legacy config keys) have no dashboard yet.
	 */
	public function testConnectionsWithoutADashboardLinkGetANotice(): void {
		$connection = new CloudConnection();
		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );

		$url = $this->billing();

		$this->assertStringContainsString( 'billing=unavailable', $url );
	}

	/**
	 * Unconfigured Cloud credentials keep the connection guard.
	 */
	public function testUnconfiguredCloudGetsANotice(): void {
		update_option( 'sentinel_forms_settings', [] );

		$url = $this->billing();

		$this->assertStringContainsString( 'billing=not_configured', $url );
	}

	/**
	 * Simulate the billing admin action with a nonce.
	 */
	private function billing(): string {
		$_POST                = [ 'action' => 'sentinel_forms_cloud_billing' ];
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'sentinel_cloud_billing' );

		add_filter(
			'sentinel_forms_redirect',
			static function ( string $url ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
				throw new RedirectedException( $url );
			}
		);

		try {
			CloudBillingController::handle();
			$this->fail( 'The controller should redirect.' );
		} catch ( RedirectedException $redirected ) {
			return $redirected->url;
		} finally {
			remove_all_filters( 'sentinel_forms_redirect' );
			unset( $_REQUEST['_wpnonce'] );
		}
	}

	/**
	 * Tear down filters.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}
}
