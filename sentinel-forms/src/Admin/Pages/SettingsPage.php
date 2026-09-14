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
	 * @return array{notification_sender_name: string, notification_sender_email: string, debug_logging: int}
	 */
	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : [];

		return [
			'notification_sender_name'  => sanitize_text_field( (string) ( $input['notification_sender_name'] ?? '' ) ),
			'notification_sender_email' => sanitize_email( (string) ( $input['notification_sender_email'] ?? '' ) ),
			'debug_logging'             => empty( $input['debug_logging'] ) ? 0 : 1,
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
