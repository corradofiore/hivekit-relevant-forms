<?php
/**
 * Cloud subscription flow integration tests (Phase 8).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Cloud\CloudConnection;
use SentinelForms\Http\CloudBillingController;
use SentinelForms\Tests\Integration\Support\RedirectedException;

/**
 * Checkout / portal redirects to provider-hosted sessions.
 *
 * @group integration
 */
final class CloudBillingFlowTest extends TestCase {

	/**
	 * Requests captured by the fake Cloud.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $requests = [];

	/**
	 * Run as an administrator and install the fake Cloud endpoints.
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
		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );

		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array {
				unset( $pre );
				$this->requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				if ( str_contains( $url, '/billing/portal' ) ) {
					return $this->json( [ 'portal_url' => 'https://billing.example/portal/session_1' ] );
				}

				if ( str_contains( $url, '/billing/checkout' ) ) {
					return $this->json( [ 'checkout_url' => 'https://billing.example/checkout/session_1' ] );
				}

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
	 * Subscribe opens the provider-hosted checkout session.
	 */
	public function testCheckoutRedirectsToTheHostedSession(): void {
		$url = $this->billing( 'checkout' );

		$this->assertSame( 'https://billing.example/checkout/session_1', $url );

		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( '/api/v1/billing/checkout', (string) $this->requests[0]['url'] );

		$payload = json_decode( (string) $this->requests[0]['args']['body'], true );
		$this->assertSame( 'cloud', $payload['plan'] );
		$this->assertStringContainsString( 'page=sentinel-settings', (string) $payload['success_url'] );
		$this->assertStringContainsString( 'billing=return', (string) $payload['success_url'] );
	}

	/**
	 * Manage billing opens the provider-hosted customer portal.
	 */
	public function testPortalRedirectsToTheHostedPortal(): void {
		$url = $this->billing( 'portal' );

		$this->assertSame( 'https://billing.example/portal/session_1', $url );
		$this->assertStringContainsString( '/api/v1/billing/portal?return_url=', (string) $this->requests[0]['url'] );
	}

	/**
	 * Disconnected sites are pointed back to the connection settings.
	 */
	public function testDisconnectedSitesCannotOpenBilling(): void {
		$connection = new CloudConnection();
		$connection->disconnect();

		$url = $this->billing( 'checkout' );

		$this->assertStringContainsString( 'page=sentinel-settings', $url );
		$this->assertStringContainsString( 'billing=not_connected', $url );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * Backend failures degrade to a settings notice, never a broken flow.
	 */
	public function testBackendFailuresRedirectBackWithANotice(): void {
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array {
				unset( $pre, $args, $url );

				return [
					'response' => [ 'code' => 502 ],
					'body'     => '{"success":false}',
				];
			},
			10,
			3
		);

		$url = $this->billing( 'checkout' );

		$this->assertStringContainsString( 'billing=failed', $url );
	}

	/**
	 * Simulate the billing admin action with a nonce.
	 *
	 * @param string $mode checkout|portal.
	 */
	private function billing( string $mode ): string {
		$_POST                = [
			'action' => 'sentinel_forms_cloud_billing',
			'mode'   => $mode,
			'plan'   => 'cloud',
		];
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
	 * JSON envelope for the fake Cloud.
	 *
	 * @param array<string, mixed> $data Payload.
	 * @return array<string, mixed>
	 */
	private function json( array $data ): array {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode(
				[
					'success' => true,
					'data'    => $data,
				]
			),
		];
	}

	/**
	 * Tear down filters.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}
}
