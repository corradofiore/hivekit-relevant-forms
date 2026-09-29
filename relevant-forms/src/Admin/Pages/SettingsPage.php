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

		// Per-form settings (notification sender, AI purpose/priorities/
		// unwanted/privacy, SMS recipient) live in the form schema now; the
		// legacy option keys are intentionally dropped from the rebuilt array
		// (hard cut — they stop being stored on the next save).
		$settings = [
			'debug_logging'                     => empty( $input['debug_logging'] ) ? 0 : 1,
			'storage_provider'                  => $storageProvider,
			'storage_block_unscanned_downloads' => empty( $input['storage_block_unscanned_downloads'] ) ? 0 : 1,
			// Legacy stored service URL: preserved for pre-1.0 installs; the settings screen no longer asks for it.
			'cloud_url'                         => esc_url_raw( (string) ( $current['cloud_url'] ?? '' ) ),
			// Keep the previous key when the field is left blank (never echoed back).
			'cloud_api_key'                     => '' !== (string) ( $input['cloud_api_key'] ?? '' )
				? sanitize_text_field( (string) $input['cloud_api_key'] )
				: (string) ( $current['cloud_api_key'] ?? '' ),
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
