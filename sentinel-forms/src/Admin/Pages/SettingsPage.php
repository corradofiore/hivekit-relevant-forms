<?php
/**
 * Settings admin page.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Pages;

/**
 * Minimal general settings: notification sender and debug logging.
 */
final class SettingsPage {

	/**
	 * Option key.
	 */
	public const OPTION = 'sentinel_forms_settings';

	/**
	 * Register the setting.
	 */
	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'registerSetting' ] );
	}

	/**
	 * Register with the Settings API.
	 */
	public static function registerSetting(): void {
		register_setting(
			'sentinel_forms_settings',
			self::OPTION,
			[ 'sanitize_callback' => [ self::class, 'sanitize' ] ]
		);
	}

	/**
	 * Sanitize the settings payload.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : [];

		$current = (array) get_option( self::OPTION, [] );

		return [
			'notification_sender_name'          => sanitize_text_field( (string) ( $input['notification_sender_name'] ?? '' ) ),
			'notification_sender_email'         => sanitize_email( (string) ( $input['notification_sender_email'] ?? '' ) ),
			'debug_logging'                     => empty( $input['debug_logging'] ) ? 0 : 1,
			'storage_provider'                  => 's3' === (string) ( $input['storage_provider'] ?? '' ) ? 's3' : 'local',
			'storage_endpoint'                  => esc_url_raw( (string) ( $input['storage_endpoint'] ?? '' ) ),
			'storage_region'                    => sanitize_text_field( (string) ( $input['storage_region'] ?? '' ) ),
			'storage_bucket'                    => sanitize_text_field( (string) ( $input['storage_bucket'] ?? '' ) ),
			'storage_access_key'                => sanitize_text_field( (string) ( $input['storage_access_key'] ?? '' ) ),
			// Keep the previous secret when the field is left blank (never echoed back).
			'storage_secret_key'                => '' !== (string) ( $input['storage_secret_key'] ?? '' )
				? sanitize_text_field( (string) $input['storage_secret_key'] )
				: (string) ( $current['storage_secret_key'] ?? '' ),
			'storage_block_unscanned_downloads' => empty( $input['storage_block_unscanned_downloads'] ) ? 0 : 1,
			'scan_clamav_path'                  => sanitize_text_field( (string) ( $input['scan_clamav_path'] ?? '' ) ),
			'scan_backend_url'                  => esc_url_raw( (string) ( $input['scan_backend_url'] ?? '' ) ),
			// Keep the previous key when the field is left blank (never echoed back).
			'scan_backend_key'                  => '' !== (string) ( $input['scan_backend_key'] ?? '' )
				? sanitize_text_field( (string) $input['scan_backend_key'] )
				: (string) ( $current['scan_backend_key'] ?? '' ),
		];
	}

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		$settings = (array) get_option( self::OPTION, [] );

		require SENTINEL_FORMS_PATH . 'templates/admin/settings.php';
	}
}
