<?php
/**
 * Cloud billing deep link (Phase 8; dashboard deep link since D8).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Http;

use SentinelForms\Cloud\CloudConfig;
use SentinelForms\Cloud\CloudConnection;

/**
 * Redirects the administrator to the site's subscription page in the
 * Sentinel Cloud dashboard. Billing actions (checkout, plan changes,
 * invoices) live there — WordPress only deep-links with the site
 * context and never talks to the payment provider itself.
 */
final class CloudBillingController {

	/**
	 * Register the admin-post action.
	 */
	public static function register(): void {
		add_action( 'admin_post_sentinel_forms_cloud_billing', [ self::class, 'handle' ] );
	}

	/**
	 * Deep-link into the client dashboard for this site.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'sentinel_cloud_billing' );

		$settingsUrl = admin_url( 'admin.php?page=sentinel-settings' );

		if ( ! CloudConfig::isConfigured() ) {
			self::redirect( $settingsUrl . '&billing=not_configured' );
		}

		$connection = new CloudConnection();

		if ( CloudConnection::STATE_DISCONNECTED === $connection->state() ) {
			self::redirect( $settingsUrl . '&billing=not_connected' );
		}

		// Deep link reported by /v1/sites/connect; absent for account-less
		// (legacy config-key) sites, which have no dashboard yet.
		$url = $connection->dashboardUrl();

		if ( null === $url ) {
			self::redirect( $settingsUrl . '&billing=unavailable' );
		}

		$url = (string) apply_filters( 'sentinel_forms_redirect', $url, 0 );

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- deep link into the Sentinel Cloud dashboard reported by our backend.
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
