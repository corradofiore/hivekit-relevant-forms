<?php
/**
 * Settings admin page.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Pages;

use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Support\Logger;

/**
 * Minimal general settings: notification sender and debug logging.
 */
final class SettingsPage {

	/**
	 * Option key.
	 */
	public const OPTION = 'relevant_forms_settings';

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
			'relevant_forms_settings',
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

		$storageProvider = (string) ( $input['storage_provider'] ?? '' );

		if ( ! in_array( $storageProvider, [ 'local', 'cloud' ], true ) ) {
			$storageProvider = 'local';
		}

		$settings = [
			'notification_sender_name'          => sanitize_text_field( (string) ( $input['notification_sender_name'] ?? '' ) ),
			'notification_sender_email'         => sanitize_email( (string) ( $input['notification_sender_email'] ?? '' ) ),
			'debug_logging'                     => empty( $input['debug_logging'] ) ? 0 : 1,
			'storage_provider'                  => $storageProvider,
			'storage_block_unscanned_downloads' => empty( $input['storage_block_unscanned_downloads'] ) ? 0 : 1,
			'scan_backend_url'                  => esc_url_raw( (string) ( $input['scan_backend_url'] ?? '' ) ),
			// Keep the previous key when the field is left blank (never echoed back).
			'scan_backend_key'                  => '' !== (string) ( $input['scan_backend_key'] ?? '' )
				? sanitize_text_field( (string) $input['scan_backend_key'] )
				: (string) ( $current['scan_backend_key'] ?? '' ),
			'cloud_url'                         => esc_url_raw( (string) ( $input['cloud_url'] ?? '' ) ),
			// Keep the previous key when the field is left blank (never echoed back).
			'cloud_api_key'                     => '' !== (string) ( $input['cloud_api_key'] ?? '' )
				? sanitize_text_field( (string) $input['cloud_api_key'] )
				: (string) ( $current['cloud_api_key'] ?? '' ),
			'ai_site_purpose'                   => mb_substr( sanitize_textarea_field( (string) ( $input['ai_site_purpose'] ?? '' ) ), 0, 500 ),
			'ai_priorities'                     => mb_substr( sanitize_textarea_field( (string) ( $input['ai_priorities'] ?? '' ) ), 0, 2000 ),
			'ai_unwanted'                       => mb_substr( sanitize_textarea_field( (string) ( $input['ai_unwanted'] ?? '' ) ), 0, 2000 ),
			'ai_privacy_mode'                   => empty( $input['ai_privacy_mode'] ) ? 0 : 1,
			'sms_recipient'                     => mb_substr( sanitize_text_field( (string) ( $input['sms_recipient'] ?? '' ) ), 0, 32 ),
		];

		self::syncCapabilityConsent( CloudConnection::CAP_AI, ! empty( $input['ai_enabled'] ) );
		self::syncCapabilityConsent( CloudConnection::CAP_SMS, ! empty( $input['sms_enabled'] ) );
		self::syncCapabilityConsent( CloudConnection::CAP_UPLOADS, 'cloud' === $storageProvider );

		return $settings;
	}

	/**
	 * Reconcile one capability consent flag with the Cloud connection
	 * state (v2 §8). Enabling requires a connected account; disabling is
	 * always allowed and takes effect immediately.
	 */
	private static function syncCapabilityConsent( string $capability, bool $requested ): void {
		$connection = new CloudConnection();

		if ( ! $requested ) {
			$connection->disableCapability( $capability );

			return;
		}

		if ( ! CloudConfig::isConfigured() || CloudConnection::STATE_DISCONNECTED === $connection->state() ) {
			Logger::info( 'Cloud capability enable requested while disconnected — ignored', [ 'capability' => $capability ] );

			return;
		}

		$connection->enableCapability( $capability );
	}

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		$settings = (array) get_option( self::OPTION, [] );

		require RELEVANT_FORMS_PATH . 'templates/admin/settings.php';
	}
}
