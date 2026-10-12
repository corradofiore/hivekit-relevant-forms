<?php
/**
 * Cloud connect/disconnect admin actions (Phase 3, v2 §8).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Http;

use RelevantForms\Admin\Pages\SettingsPage;
use RelevantForms\Cloud\CloudClient;
use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Cloud\CloudConnection;

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
		add_action( 'admin_post_relevant_forms_cloud_connect', [ self::class, 'connect' ] );
		add_action( 'admin_post_relevant_forms_cloud_refresh', [ self::class, 'refresh' ] );
		add_action( 'admin_post_relevant_forms_cloud_disconnect', [ self::class, 'disconnect' ] );
		add_action( 'admin_post_relevant_forms_cloud_save_key', [ self::class, 'saveKey' ] );
	}

	/**
	 * Bind the configured site key to a Cloud account.
	 */
	public static function connect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'relevant_cloud_connect' );

		if ( ! CloudConfig::isConfigured() ) {
			self::redirect( 'not_configured' );
		}

		$data = ( new CloudClient( CloudConfig::apiBase(), CloudConfig::key() ) )
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
			is_string( $data['dashboard_url'] ?? null ) ? (string) $data['dashboard_url'] : null,
			is_array( $data['account'] ?? null ) ? $data['account'] : []
		);
		$connection->updateSubscription( is_array( $data['subscription'] ?? null ) ? $data['subscription'] : [] );

		self::redirect( 'connected' );
	}

	/**
	 * Refresh the cached subscription summary and server capabilities
	 * from the capabilities endpoint (e.g. after subscribing, renewing or
	 * cancelling in the dashboard). The server list is data, not consent: local
	 * capability enable flags stay untouched.
	 */
	public static function refresh(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'relevant_cloud_refresh' );

		if ( ! CloudConfig::isConfigured() ) {
			self::redirect( 'not_configured' );
		}

		$connection = new CloudConnection();
		$siteId     = $connection->siteId();

		if ( null === $siteId || CloudConnection::STATE_DISCONNECTED === $connection->state() ) {
			self::redirect( 'not_connected' );
		}

		$data = ( new CloudClient( CloudConfig::apiBase(), CloudConfig::key() ) )
			->capabilities( (string) $siteId );

		if ( null === $data ) {
			self::redirect( 'refresh_failed' );
		}

		$connection->updateServerCapabilities( is_array( $data['capabilities'] ?? null ) ? $data['capabilities'] : [] );
		$connection->updateSubscription( is_array( $data['subscription'] ?? null ) ? $data['subscription'] : [] );
		$connection->updateAccount( is_array( $data['account'] ?? null ) ? $data['account'] : [] );

		self::redirect( 'refreshed' );
	}

	/**
	 * Forget the Cloud binding; Core continues locally (v2 §8).
	 */
	public static function disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'relevant_cloud_disconnect' );

		( new CloudConnection() )->disconnect();

		self::redirect( 'disconnected' );
	}

	/**
	 * Store a Cloud API key entered on the settings screen. The key field
	 * saves on change; an empty value keeps the stored key.
	 */
	public static function saveKey(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'relevant_cloud_save_key' );

		$raw = wp_unslash( $_POST );
		$key = (string) sanitize_text_field( (string) ( $raw['cloud_api_key'] ?? '' ) );

		if ( '' === $key ) {
			wp_safe_redirect( admin_url( 'admin.php?page=relevant-settings' ) );
			exit;
		}

		$settings = (array) get_option( SettingsPage::OPTION, [] );

		/*
		 * A different key addresses a different Cloud site, and the cached
		 * binding (site id, plan, capabilities, dashboard link) all describe
		 * the previous one. Releasing it here keeps the operator from seeing
		 * the old site's entitlement after the swap; consent goes with it,
		 * because it was granted for the site being replaced.
		 */
		$replaced = (string) ( $settings['cloud_api_key'] ?? '' ) !== $key;

		$settings['cloud_api_key'] = $key;

		update_option( SettingsPage::OPTION, $settings );

		$connection = new CloudConnection();

		if ( $replaced && null !== $connection->siteId() ) {
			$connection->disconnect();

			self::redirect( 'key_changed' );
		}

		self::redirect( 'key_saved' );
	}

	/**
	 * Redirect back to the settings page with a status marker.
	 */
	private static function redirect( string $status ): never {
		$target = add_query_arg(
			[ 'cloud' => $status ],
			admin_url( 'admin.php?page=relevant-settings' )
		);

		// Test seam: allows integration tests to intercept the redirect.
		$target = (string) apply_filters( 'relevant_forms_redirect', $target, 0 );

		wp_safe_redirect( $target );
		exit;
	}
}
