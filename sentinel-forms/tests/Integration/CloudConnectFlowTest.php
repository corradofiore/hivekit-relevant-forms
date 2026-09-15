<?php
/**
 * Cloud connect/disconnect integration tests (v2 §8 consent rules).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Cloud\CloudConnection;
use SentinelForms\Http\CloudController;
use SentinelForms\Tests\Integration\Support\RedirectedException;

/**
 * Explicit consent flow on real WordPress: no calls until configured,
 * connect binds the site, disconnect restores local-only operation.
 *
 * @group integration
 */
final class CloudConnectFlowTest extends TestCase {

	/**
	 * Run as an administrator so capability checks pass.
	 */
	public function setUp(): void {
		parent::setUp();

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
	}

	/**
	 * Configured-but-never-connected: zero external requests.
	 */
	public function testConnectWithoutConfigurationMakesNoRequest(): void {
		update_option( 'sentinel_forms_settings', [] );

		$requests = 0;
		add_filter(
			'pre_http_request',
			static function ( mixed $pre, array $args, string $url ) use ( &$requests ): mixed {
				unset( $pre, $args, $url ); // Counting only: no request may be made.
				$requests++;

				return [
					'response' => [ 'code' => 500 ],
					'body'     => '',
				];
			},
			10,
			3
		);

		$url = $this->connect();

		$this->assertStringContainsString( 'cloud=not_configured', $url );
		$this->assertSame( 0, $requests );
		$this->assertSame( CloudConnection::STATE_DISCONNECTED, ( new CloudConnection() )->state() );
	}

	/**
	 * A successful connect binds the site and stores capabilities.
	 */
	public function testConnectBindsSiteAndRecordsConsent(): void {
		update_option(
			'sentinel_forms_settings',
			[
				'cloud_url'     => 'https://cloud.example.com',
				'cloud_api_key' => 'site-key-1',
			]
		);

		add_filter(
			'pre_http_request',
			static function ( mixed $pre, array $args, string $url ): array {
				unset( $pre, $args, $url ); // Canned response for the connect endpoint.

				return [
					'response' => [ 'code' => 201 ],
					'body'     => wp_json_encode(
						[
							'success' => true,
							'data'    => [
								'site_id'      => 'site-uuid-1',
								'status'       => 'active',
								'capabilities' => [ 'deep_scan' => true ],
								'subscription' => [
									'plan'     => 'cloud',
									'status'   => 'active',
									'entitled' => true,
								],
							],
						]
					),
				];
			},
			10,
			3
		);

		$url = $this->connect();

		$this->assertStringContainsString( 'cloud=connected', $url );

		$connection = new CloudConnection();
		$this->assertSame( CloudConnection::STATE_ACCOUNT_CONNECTED, $connection->state() );
		$this->assertSame( 'site-uuid-1', $connection->siteId() );
		$this->assertSame( [ 'deep_scan' => true ], $connection->serverCapabilities() );

		// Phase 8: the subscription summary is cached with the connection.
		$this->assertSame( 'cloud', $connection->subscription()['plan'] ?? null );
		$this->assertTrue( $connection->subscription()['entitled'] ?? false );

		// Consent gate: capabilities require explicit enablement.
		$this->assertFalse( $connection->capabilityEnabled( CloudConnection::CAP_AI ) );
		$this->assertTrue( $connection->enableCapability( CloudConnection::CAP_AI ) );
		$this->assertSame( CloudConnection::STATE_AI_ENABLED, $connection->state() );
	}

	/**
	 * Disconnect restores local-only operation without external calls.
	 */
	public function testDisconnectRestoresLocalOnlyOperation(): void {
		$connection = new CloudConnection();
		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme', [ 'deep_scan' => true ] );
		$connection->enableCapability( CloudConnection::CAP_SMS );

		$url = $this->disconnect();

		$this->assertStringContainsString( 'cloud=disconnected', $url );
		$this->assertSame( CloudConnection::STATE_DISCONNECTED, ( new CloudConnection() )->state() );
	}

	/**
	 * Simulate the connect admin action with a nonce.
	 */
	private function connect(): string {
		$_POST                = [ 'action' => 'sentinel_forms_cloud_connect' ];
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'sentinel_cloud_connect' );

		return $this->invokeController();
	}

	/**
	 * Simulate the disconnect admin action with a nonce.
	 */
	private function disconnect(): string {
		$_POST                = [ 'action' => 'sentinel_forms_cloud_disconnect' ];
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'sentinel_cloud_disconnect' );

		return $this->invokeController();
	}

	/**
	 * Invoke the controller and capture the redirect target.
	 */
	private function invokeController(): string {
		add_filter(
			'sentinel_forms_redirect',
			static function ( string $url ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
				throw new RedirectedException( $url );
			}
		);

		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- action name set by the test itself.
			if ( 'sentinel_forms_cloud_disconnect' === (string) ( $_POST['action'] ?? '' ) ) {
				CloudController::disconnect();
			} else {
				CloudController::connect();
			}
		} catch ( RedirectedException $redirected ) {
			return $redirected->url;
		} finally {
			remove_all_filters( 'sentinel_forms_redirect' );
		}

		return '';
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		unset( $_REQUEST['_wpnonce'] );
		parent::tearDown();
	}
}
