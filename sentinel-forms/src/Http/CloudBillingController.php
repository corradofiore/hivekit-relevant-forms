<?php
/**
 * Cloud subscription actions (Phase 8): hosted checkout and portal.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Http;

use SentinelForms\Cloud\CloudClient;
use SentinelForms\Cloud\CloudConfig;
use SentinelForms\Cloud\CloudConnection;

/**
 * Redirects the administrator to the provider-hosted billing session
 * created by our backend. No billing data ever lives in WordPress; the
 * site only holds the app-side connection state.
 */
final class CloudBillingController {

	/**
	 * Register the admin-post action.
	 */
	public static function register(): void {
		add_action( 'admin_post_sentinel_forms_cloud_billing', [ self::class, 'handle' ] );
	}

	/**
	 * Handle a checkout or portal request.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'sentinel_cloud_billing' );

		$raw         = wp_unslash( $_POST );
		$mode        = sanitize_key( (string) ( $raw['mode'] ?? 'checkout' ) );
		$settingsUrl = admin_url( 'admin.php?page=sentinel-settings' );

		if ( ! CloudConfig::isConfigured() ) {
			self::redirect( $settingsUrl . '&billing=not_configured' );
		}

		$connection = new CloudConnection();

		if ( CloudConnection::STATE_DISCONNECTED === $connection->state() ) {
			self::redirect( $settingsUrl . '&billing=not_connected' );
		}

		$client = new CloudClient( CloudConfig::url(), CloudConfig::key() );
		$url    = '';

		if ( 'portal' === $mode ) {
			$data = $client->billingPortal( $settingsUrl . '&billing=return' );
			$url  = is_array( $data ) ? (string) ( $data['portal_url'] ?? '' ) : '';
		} else {
			$plan = sanitize_key( (string) ( $raw['plan'] ?? 'cloud' ) );

			if ( '' === $plan ) {
				$plan = 'cloud';
			}

			$data = $client->billingCheckout( $plan, $settingsUrl . '&billing=return', $settingsUrl . '&billing=cancel' );
			$url  = is_array( $data ) ? (string) ( $data['checkout_url'] ?? '' ) : '';
		}

		// Only secure URLs returned by our own backend are followed.
		if ( '' === $url || ! str_starts_with( $url, 'https://' ) ) {
			self::redirect( $settingsUrl . '&billing=failed' );
		}

		$url = (string) apply_filters( 'sentinel_forms_redirect', $url, 0 );

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external hosted billing session returned by our backend.
		wp_redirect( $url );
		exit;
	}

	/**
	 * Internal redirect (settings markers).
	 */
	private static function redirect( string $url ): never {
		$url = (string) apply_filters( 'sentinel_forms_redirect', $url, 0 );

		wp_safe_redirect( $url );
		exit;
	}
}
