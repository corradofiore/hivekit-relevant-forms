<?php
/**
 * Cloud connect/disconnect integration tests (v2 §8 consent rules).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Pages\SettingsPage;
use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Http\CloudController;
use RelevantForms\Tests\Integration\Support\RedirectedException;

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
		update_option( 'relevant_forms_settings', [] );

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
			'relevant_forms_settings',
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
								'site_id'       => 'site-uuid-1',
								'status'        => 'active',
								'dashboard_url' => 'https://cloud.example.com/app/sites/site-uuid-1/billing',
								'capabilities'  => [ 'deep_scan' => true ],
								'subscription'  => [
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

		// D8: the dashboard deep link is cached for the Billing link.
		$this->assertSame( 'https://cloud.example.com/app/sites/site-uuid-1/billing', $connection->dashboardUrl() );

		// Consent gate: capabilities require explicit enablement.
		$this->assertFalse( $connection->capabilityEnabled( CloudConnection::CAP_AI ) );
		$this->assertTrue( $connection->enableCapability( CloudConnection::CAP_AI ) );
		$this->assertSame( CloudConnection::STATE_AI_ENABLED, $connection->state() );
	}

	/**
	 * Refresh re-reads the subscription and capabilities from Cloud.
	 */
	public function testRefreshUpdatesCachedSubscriptionAndCapabilities(): void {
		update_option(
			'relevant_forms_settings',
			[
				'cloud_url'     => 'https://cloud.example.com',
				'cloud_api_key' => 'site-key-1',
			]
		);

		$connection = new CloudConnection();
		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme', [ 'deep_scan' => true ] );

		add_filter(
			'pre_http_request',
			static function ( mixed $pre, array $args, string $url ): array {
				unset( $pre, $args );

				// The refresh must hit the site capabilities endpoint.
				if ( ! str_contains( $url, '/api/v1/sites/site-uuid-1/capabilities' ) ) {
					return [
						'response' => [ 'code' => 404 ],
						'body'     => '',
					];
				}

				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'success' => true,
							'data'    => [
								'capabilities' => [
									'deep_scan' => true,
									'semantic_classification' => true,
								],
								'subscription' => [
									'plan'     => 'cloud',
									'status'   => 'active',
									'active'   => true,
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

		$url = $this->refresh();

		$this->assertStringContainsString( 'cloud=refreshed', $url );

		$connection = new CloudConnection();
		$this->assertSame( 'active', $connection->subscription()['status'] ?? null );
		$this->assertTrue( $connection->subscription()['entitled'] ?? false );
		$this->assertSame(
			[
				'deep_scan'               => true,
				'semantic_classification' => true,
			],
			$connection->serverCapabilities()
		);
	}

	/**
	 * A failed refresh keeps the cached state and reports the failure.
	 */
	public function testRefreshFailureKeepsCachedState(): void {
		update_option(
			'relevant_forms_settings',
			[
				'cloud_url'     => 'https://cloud.example.com',
				'cloud_api_key' => 'site-key-1',
			]
		);

		$connection = new CloudConnection();
		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme', [ 'deep_scan' => true ] );
		$connection->updateSubscription(
			[
				'plan'     => 'cloud',
				'status'   => 'active',
				'entitled' => true,
			]
		);

		add_filter(
			'pre_http_request',
			static function ( mixed $pre, array $args, string $url ): array {
				unset( $pre, $args, $url );

				return [
					'response' => [ 'code' => 500 ],
					'body'     => '',
				];
			},
			10,
			3
		);

		$url = $this->refresh();

		$this->assertStringContainsString( 'cloud=refresh_failed', $url );
		$this->assertSame( 'active', ( new CloudConnection() )->subscription()['status'] ?? null );
	}

	/**
	 * Without any stored URL the client talks to the built-in service.
	 */
	public function testConnectUsesTheBuiltInServiceUrlByDefault(): void {
		update_option( 'relevant_forms_settings', [ 'cloud_api_key' => 'site-key-1' ] );

		$requested = [];

		add_filter(
			'pre_http_request',
			static function ( mixed $pre, array $args, string $url ) use ( &$requested ): array {
				unset( $pre, $args );

				$requested[] = $url;

				return [
					'response' => [ 'code' => 201 ],
					'body'     => wp_json_encode(
						[
							'success' => true,
							'data'    => [
								'site_id'      => 'site-uuid-1',
								'status'       => 'active',
								'capabilities' => [ 'deep_scan' => true ],
								'subscription' => [],
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
		$this->assertSame( 'https://api.relevantcloud.com/api/v1/sites/connect', $requested[0] ?? '' );
	}

	/**
	 * The settings screen asks only for the site key.
	 */
	public function testSettingsScreenDoesNotAskForTheCloudUrl(): void {
		update_option( 'relevant_forms_settings', [] );

		set_current_screen( 'toplevel_page_relevant-settings' );

		ob_start();
		SettingsPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'cloud_url', $html );
		$this->assertStringNotContainsString( 'Cloud URL', $html );
		$this->assertStringContainsString( 'cloud_api_key', $html );
	}

	/**
	 * Deep scanning is provided by the Cloud: no separate credentials asked.
	 */
	public function testSettingsScreenDoesNotAskForScanBackendCredentials(): void {
		update_option( 'relevant_forms_settings', [] );

		set_current_screen( 'toplevel_page_relevant-settings' );

		ob_start();
		SettingsPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'scan_backend_url', $html );
		$this->assertStringNotContainsString( 'scan_backend_key', $html );
		$this->assertStringNotContainsString( 'Scan backend', $html );
	}

	/**
	 * A stored key renders masked with an Edit reveal; the full key is
	 * never echoed back into the page.
	 */
	public function testStoredKeyRendersMaskedWithAnEditReveal(): void {
		$key = 'sfk_1234567890abcdef1234567890abcdef';

		update_option( 'relevant_forms_settings', [ 'cloud_api_key' => $key ] );

		set_current_screen( 'toplevel_page_relevant-settings' );

		ob_start();
		SettingsPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( CloudConfig::mask( $key ), $html );
		$this->assertStringNotContainsString( $key, $html );
		$this->assertStringContainsString( 'relevant-cloud-key-edit', $html );
		$this->assertStringContainsString( 'Paste a new key here', $html );
	}

	/**
	 * No stored key: a plain blank field, no preview and no Edit reveal.
	 */
	public function testMissingKeyRendersThePlainBlankField(): void {
		update_option( 'relevant_forms_settings', [] );

		set_current_screen( 'toplevel_page_relevant-settings' );

		ob_start();
		SettingsPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'relevant-cloud-key-edit', $html );
		$this->assertStringNotContainsString( 'Paste a new key here', $html );
		$this->assertStringContainsString( 'name="cloud_api_key"', $html );
	}

	/**
	 * Saving with the replacement field blank keeps the stored key; a
	 * filled field replaces it.
	 */
	public function testBlankReplacementFieldKeepsTheStoredKey(): void {
		update_option( 'relevant_forms_settings', [ 'cloud_api_key' => 'sfk_original_key_1234567890' ] );

		$kept = SettingsPage::sanitize( [] );
		$this->assertSame( 'sfk_original_key_1234567890', $kept['cloud_api_key'] ?? '' );

		$replaced = SettingsPage::sanitize( [ 'cloud_api_key' => 'sfk_replacement_key_0987654321' ] );
		$this->assertSame( 'sfk_replacement_key_0987654321', $replaced['cloud_api_key'] ?? '' );
	}

	/**
	 * Per-form settings moved to the form editor: the settings screen no
	 * longer asks for them, and the legacy keys are dropped on save.
	 */
	public function testSettingsScreenDropsTheLegacyPerFormKeys(): void {
		update_option(
			'relevant_forms_settings',
			[
				'cloud_api_key'             => 'site-key-1',
				'notification_sender_name'  => 'Old Sender',
				'notification_sender_email' => 'old@example.com',
				'ai_site_purpose'           => 'Old purpose',
				'ai_priorities'             => 'Old priorities',
				'ai_unwanted'               => 'Old unwanted',
				'ai_privacy_mode'           => 0,
				'sms_recipient'             => '+3912345',
			]
		);

		set_current_screen( 'toplevel_page_relevant-settings' );

		ob_start();
		SettingsPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'notification_sender_name', $html );
		$this->assertStringNotContainsString( 'notification_sender_email', $html );
		$this->assertStringNotContainsString( 'ai_site_purpose', $html );
		$this->assertStringNotContainsString( 'ai_priorities', $html );
		$this->assertStringNotContainsString( 'ai_unwanted', $html );
		$this->assertStringNotContainsString( 'ai_privacy_mode', $html );
		$this->assertStringNotContainsString( 'sms_recipient', $html );

		$sanitized = SettingsPage::sanitize( [] );

		$this->assertArrayNotHasKey( 'notification_sender_name', $sanitized );
		$this->assertArrayNotHasKey( 'ai_site_purpose', $sanitized );
		$this->assertArrayNotHasKey( 'ai_priorities', $sanitized );
		$this->assertArrayNotHasKey( 'ai_unwanted', $sanitized );
		$this->assertArrayNotHasKey( 'ai_privacy_mode', $sanitized );
		$this->assertArrayNotHasKey( 'sms_recipient', $sanitized );
		$this->assertSame( 'site-key-1', $sanitized['cloud_api_key'] );
	}

	/**
	 * No capability on/off switches in the general settings: AI and SMS are
	 * enabled per form (the consent follows the first opt-in).
	 */
	public function testSettingsScreenHasNoCapabilitySwitches(): void {
		update_option( 'relevant_forms_settings', [] );

		set_current_screen( 'toplevel_page_relevant-settings' );

		ob_start();
		SettingsPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'ai_enabled', $html );
		$this->assertStringNotContainsString( 'sms_enabled', $html );
		$this->assertStringNotContainsString( 'Enable classification', $html );
		$this->assertStringNotContainsString( 'AI classification', $html );
		$this->assertStringNotContainsString( 'Priority SMS alerts', $html );
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
		$_POST                = [ 'action' => 'relevant_forms_cloud_connect' ];
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_cloud_connect' );

		return $this->invokeController();
	}

	/**
	 * Simulate the disconnect admin action with a nonce.
	 */
	private function disconnect(): string {
		$_POST                = [ 'action' => 'relevant_forms_cloud_disconnect' ];
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_cloud_disconnect' );

		return $this->invokeController();
	}

	/**
	 * Simulate the refresh admin action with a nonce.
	 */
	private function refresh(): string {
		$_POST                = [ 'action' => 'relevant_forms_cloud_refresh' ];
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_cloud_refresh' );

		return $this->invokeController();
	}

	/**
	 * Invoke the controller and capture the redirect target.
	 */
	private function invokeController(): string {
		add_filter(
			'relevant_forms_redirect',
			static function ( string $url ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
				throw new RedirectedException( $url );
			}
		);

		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- action name set by the test itself.
			$action = (string) ( $_POST['action'] ?? '' );

			if ( 'relevant_forms_cloud_disconnect' === $action ) {
				CloudController::disconnect();
			} elseif ( 'relevant_forms_cloud_refresh' === $action ) {
				CloudController::refresh();
			} else {
				CloudController::connect();
			}
		} catch ( RedirectedException $redirected ) {
			return $redirected->url;
		} finally {
			remove_all_filters( 'relevant_forms_redirect' );
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
