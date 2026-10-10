<?php
/**
 * Settings admin page.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Pages;

use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Support\Logger;
use RelevantForms\Validation\EmailDeliverability;

/**
 * General settings: file storage and the Relevant Cloud connection.
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

		// The debug-logging toggle lives on the Logs screen, so the key is
		// absent from this form's input: keep the stored value when absent.
		$debugLogging = (int) ! empty( $current['debug_logging'] );

		if ( isset( $input['debug_logging'] ) ) {
			$debugLogging = empty( $input['debug_logging'] ) ? 0 : 1;
		}

		// The checkbox is only sent when ticked; an absent select (a save from
		// another screen sharing this option) keeps the stored mode.
		$emailCheckMode = (string) ( $input['email_check_mode'] ?? ( $current['email_check_mode'] ?? EmailDeliverability::MODE_OFF ) );

		if ( ! in_array( $emailCheckMode, EmailDeliverability::modes(), true ) ) {
			$emailCheckMode = EmailDeliverability::MODE_OFF;
		}

		// Cached answers were collected under the previous policy: drop them so
		// the new one takes effect on the next submission.
		if ( $emailCheckMode !== (string) ( $current['email_check_mode'] ?? '' ) ) {
			EmailDeliverability::flush();
		}

		// Per-form settings (notification sender, AI purpose/priorities/
		// unwanted/privacy, SMS recipient) live in the form schema now; the
		// legacy option keys are intentionally dropped from the rebuilt array
		// (hard cut — they stop being stored on the next save).
		$settings = [
			'debug_logging'                     => $debugLogging,
			'storage_provider'                  => $storageProvider,
			'storage_block_unscanned_downloads' => empty( $input['storage_block_unscanned_downloads'] ) ? 0 : 1,
			'email_check_mode'                  => $emailCheckMode,
			'email_check_reject_unreachable'    => empty( $input['email_check_reject_unreachable'] ) ? 0 : 1,
			// Legacy stored service URL: preserved for pre-1.0 installs; the settings screen no longer asks for it.
			'cloud_url'                         => esc_url_raw( (string) ( $current['cloud_url'] ?? '' ) ),
			// Keep the previous key when the field is left blank (never echoed back).
			'cloud_api_key'                     => '' !== (string) ( $input['cloud_api_key'] ?? '' )
				? sanitize_text_field( (string) $input['cloud_api_key'] )
				: (string) ( $current['cloud_api_key'] ?? '' ),
		];

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

		if ( $connection->capabilityEnabled( $capability ) ) {
			return;
		}

		if ( ! $connection->grantCapability( $capability ) ) {
			Logger::info( 'Cloud capability enable requested while disconnected — ignored', [ 'capability' => $capability ] );
		}
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
