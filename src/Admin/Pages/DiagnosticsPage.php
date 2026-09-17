<?php
/**
 * Diagnostics admin page (doc §58).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Pages;

use RelevantForms\Uploads\Scanning\BackendScanConfig;
use RelevantForms\Uploads\Scanning\ScannerFactory;
use RelevantForms\Uploads\Storage\StorageConfig;
use RelevantForms\Alerts\SmsAlert;
use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Cloud\CloudConnection;

/**
 * Environment and provider status overview.
 */
final class DiagnosticsPage {

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		global $wpdb;

		$tables = [
			'relevant_forms'         => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'relevant_forms' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'relevant_form_versions' => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'relevant_form_versions' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'relevant_entries'       => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'relevant_entries' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'relevant_entry_meta'    => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'relevant_entry_meta' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'relevant_events'        => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'relevant_events' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'relevant_logs'          => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'relevant_logs' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'relevant_uploads'       => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'relevant_uploads' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		];

		$dbVersion = (string) get_option( 'relevant_forms_db_version', '0' );
		$settings  = (array) get_option( 'relevant_forms_settings', [] );

		// Provider statuses are placeholders until their phases land.
		$providers = [
			'AI provider'       => self::aiStatus(),
			'SMS provider'      => self::smsStatus(),
			'Storage provider'  => self::storageStatus(),
			'Malware scanner'   => self::scannerStatus(),
			'Deep scan backend' => self::backendScanStatus(),
			'Relevant Cloud'    => self::cloudStatus(),
		];

		require RELEVANT_FORMS_PATH . 'templates/admin/diagnostics.php';
	}

	/**
	 * Human-readable storage provider status.
	 */
	private static function storageStatus(): string {
		if ( 'cloud' === StorageConfig::provider() ) {
			return StorageConfig::isCloudUsable()
				? __( 'Relevant Managed Secure Storage', 'relevant-forms' )
				: __( 'Managed storage selected but not usable (Cloud not connected or capability disabled) — falling back to local', 'relevant-forms' );
		}

		return __( 'Local private directory', 'relevant-forms' );
	}

	/**
	 * Human-readable malware scanner status.
	 */
	private static function scannerStatus(): string {
		$scanner = ScannerFactory::make();
		$parts   = [ __( 'Basic (pure PHP) — always available', 'relevant-forms' ) ];

		if ( 'php-basic' !== $scanner->name() ) {
			$parts[] = $scanner->isAvailable()
				? sprintf(
					/* translators: %s: scanner name */
					__( '%s available (extension scanner)', 'relevant-forms' ),
					$scanner->name(),
				)
				: sprintf(
					/* translators: %s: scanner name */
					__( '%s unavailable (extension scanner)', 'relevant-forms' ),
					$scanner->name(),
				);
		}

		return implode( '; ', $parts );
	}

	/**
	 * Human-readable deep-scan backend status.
	 */
	private static function backendScanStatus(): string {
		return BackendScanConfig::isConfigured()
			? sprintf(
				/* translators: %s: backend URL */
				__( 'Configured (%s)', 'relevant-forms' ),
				BackendScanConfig::url(),
			)
			: __( 'Not configured — deep scans fail closed', 'relevant-forms' );
	}

	/**
	 * Human-readable AI classification status (Phase 4).
	 */
	private static function aiStatus(): string {
		if ( ! CloudConfig::isConfigured() ) {
			return __( 'Not configured — no external calls', 'relevant-forms' );
		}

		$connection = new CloudConnection();

		if ( ! $connection->capabilityEnabled( CloudConnection::CAP_AI ) ) {
			return __( 'Cloud configured — semantic classification disabled', 'relevant-forms' );
		}

		return __( 'Relevant Cloud — semantic classification enabled', 'relevant-forms' );
	}

	/**
	 * Human-readable managed SMS status (Phase 5).
	 */
	private static function smsStatus(): string {
		if ( ! CloudConfig::isConfigured() ) {
			return __( 'Not configured — no external calls', 'relevant-forms' );
		}

		$connection = new CloudConnection();

		if ( ! $connection->capabilityEnabled( CloudConnection::CAP_SMS ) ) {
			return __( 'Cloud configured — priority SMS disabled', 'relevant-forms' );
		}

		$recipient = ( new SmsAlert() )->recipient();

		if ( '' === $recipient ) {
			return __( 'Priority SMS enabled — no recipient configured', 'relevant-forms' );
		}

		return sprintf(
			/* translators: %s: alert recipient phone number */
			__( 'Relevant Cloud — priority SMS to %s', 'relevant-forms' ),
			$recipient,
		);
	}

	/**
	 * Human-readable Relevant Cloud connection state (v2 §8).
	 */
	private static function cloudStatus(): string {
		$connection = new CloudConnection();
		$state      = $connection->state();

		if ( CloudConnection::STATE_DISCONNECTED === $state ) {
			return CloudConfig::isConfigured()
				? __( 'Configured but not connected — no external calls', 'relevant-forms' )
				: __( 'Not configured — no external calls', 'relevant-forms' );
		}

		return sprintf(
			/* translators: 1: connection state, 2: site id */
			__( '%1$s (site %2$s)', 'relevant-forms' ),
			$state,
			(string) $connection->siteId(),
		);
	}
}
