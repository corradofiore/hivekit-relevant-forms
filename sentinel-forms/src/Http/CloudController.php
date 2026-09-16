<?php
/**
 * Cloud connect/disconnect admin actions (Phase 3, v2 §8).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Http;

use SentinelForms\Cloud\CloudClient;
use SentinelForms\Cloud\CloudConfig;
use SentinelForms\Cloud\CloudConnection;

/**
 * Explicit, consent-driven Cloud connection flow. Nothing external is
 * contacted before the administrator submits these actions with a valid
 * nonce and capability (v2 §7 consent rule).
 */
final class CloudController {

	/**
	 * Register endpoints.
	 */
	public static function register(): void {
		add_action( 'admin_post_sentinel_forms_cloud_connect', [ self::class, 'connect' ] );
		add_action( 'admin_post_sentinel_forms_cloud_disconnect', [ self::class, 'disconnect' ] );
	}

	/**
	 * Bind the configured site key to a Cloud account.
	 */
	public static function connect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'sentinel_cloud_connect' );

		if ( ! CloudConfig::isConfigured() ) {
			self::redirect( 'not_configured' );
		}

		$data = ( new CloudClient( CloudConfig::url(), CloudConfig::key() ) )
			->connect( home_url( '/' ), (string) get_bloginfo( 'name' ) );

		if ( null === $data || '' === (string) ( $data['site_id'] ?? '' ) ) {
			self::redirect( 'failed' );
		}

		$connection = new CloudConnection();
		$connection->connect(
			(string) $data['site_id'],
			home_url( '/' ),
			(string) get_bloginfo( 'name' ),
			is_array( $data['capabilities'] ?? null ) ? $data['capabilities'] : [],
			is_string( $data['dashboard_url'] ?? null ) ? (string) $data['dashboard_url'] : null
		);
		$connection->updateSubscription( is_array( $data['subscription'] ?? null ) ? $data['subscription'] : [] );

		self::redirect( 'connected' );
	}

	/**
	 * Forget the Cloud binding; Core continues locally (v2 §8).
	 */
	public static function disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'sentinel_cloud_disconnect' );

		( new CloudConnection() )->disconnect();

		self::redirect( 'disconnected' );
	}

	/**
	 * Redirect back to the settings page with a status marker.
	 */
	private static function redirect( string $status ): never {
		$target = add_query_arg(
			[ 'cloud' => $status ],
			admin_url( 'admin.php?page=sentinel-settings' )
		);

		// Test seam: allows integration tests to intercept the redirect.
		$target = (string) apply_filters( 'sentinel_forms_redirect', $target, 0 );

		wp_safe_redirect( $target );
		exit;
	}
}
