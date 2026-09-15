<?php
/**
 * Diagnostics admin page (doc §58).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Pages;

use SentinelForms\Uploads\Scanning\BackendScanConfig;
use SentinelForms\Uploads\Scanning\ScannerFactory;
use SentinelForms\Uploads\Storage\StorageConfig;
use SentinelForms\Alerts\SmsAlert;
use SentinelForms\Cloud\CloudConfig;
use SentinelForms\Cloud\CloudConnection;

/**
 * Environment and provider status overview.
 */
final class DiagnosticsPage {

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		global $wpdb;

		$tables = [
			'sentinel_forms'         => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sentinel_forms' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'sentinel_form_versions' => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sentinel_form_versions' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'sentinel_entries'       => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sentinel_entries' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'sentinel_entry_meta'    => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sentinel_entry_meta' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'sentinel_events'        => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sentinel_events' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'sentinel_logs'          => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sentinel_logs' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'sentinel_uploads'       => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sentinel_uploads' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		];

		$dbVersion = (string) get_option( 'sentinel_forms_db_version', '0' );
		$settings  = (array) get_option( 'sentinel_forms_settings', [] );

		// Provider statuses are placeholders until their phases land.
		$providers = [
			'AI provider'       => self::aiStatus(),
			'SMS provider'      => self::smsStatus(),
			'Storage provider'  => self::storageStatus(),
			'Malware scanner'   => self::scannerStatus(),
			'Deep scan backend' => self::backendScanStatus(),
			'Sentinel Cloud'    => self::cloudStatus(),
		];

		require SENTINEL_FORMS_PATH . 'templates/admin/diagnostics.php';
	}

	/**
	 * Human-readable storage provider status.
	 */
	private static function storageStatus(): string {
		if ( 'cloud' === StorageConfig::provider() ) {
			return StorageConfig::isCloudUsable()
				? __( 'Sentinel Managed Secure Storage', 'sentinel-forms' )
				: __( 'Managed storage selected but not usable (Cloud not connected or capability disabled) — falling back to local', 'sentinel-forms' );
		}

		return __( 'Local private directory', 'sentinel-forms' );
	}

	/**
	 * Human-readable malware scanner status.
	 */
	private static function scannerStatus(): string {
		$scanner = ScannerFactory::make();
		$parts   = [ __( 'Basic (pure PHP) — always available', 'sentinel-forms' ) ];

		if ( 'php-basic' !== $scanner->name() ) {
			$parts[] = $scanner->isAvailable()
				? sprintf(
					/* translators: %s: scanner name */
					__( '%s available (extension scanner)', 'sentinel-forms' ),
					$scanner->name(),
				)
				: sprintf(
					/* translators: %s: scanner name */
					__( '%s unavailable (extension scanner)', 'sentinel-forms' ),
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
				__( 'Configured (%s)', 'sentinel-forms' ),
				BackendScanConfig::url(),
			)
			: __( 'Not configured — deep scans fail closed', 'sentinel-forms' );
	}

	/**
	 * Human-readable AI classification status (Phase 4).
	 */
	private static function aiStatus(): string {
		if ( ! CloudConfig::isConfigured() ) {
			return __( 'Not configured — no external calls', 'sentinel-forms' );
		}

		$connection = new CloudConnection();

		if ( ! $connection->capabilityEnabled( CloudConnection::CAP_AI ) ) {
			return __( 'Cloud configured — semantic classification disabled', 'sentinel-forms' );
		}

		return __( 'Sentinel Cloud — semantic classification enabled', 'sentinel-forms' );
	}

	/**
	 * Human-readable managed SMS status (Phase 5).
	 */
	private static function smsStatus(): string {
		if ( ! CloudConfig::isConfigured() ) {
			return __( 'Not configured — no external calls', 'sentinel-forms' );
		}

		$connection = new CloudConnection();

		if ( ! $connection->capabilityEnabled( CloudConnection::CAP_SMS ) ) {
			return __( 'Cloud configured — priority SMS disabled', 'sentinel-forms' );
		}

		$recipient = ( new SmsAlert() )->recipient();

		if ( '' === $recipient ) {
			return __( 'Priority SMS enabled — no recipient configured', 'sentinel-forms' );
		}

		return sprintf(
			/* translators: %s: alert recipient phone number */
			__( 'Sentinel Cloud — priority SMS to %s', 'sentinel-forms' ),
			$recipient,
		);
	}

	/**
	 * Human-readable Sentinel Cloud connection state (v2 §8).
	 */
	private static function cloudStatus(): string {
		$connection = new CloudConnection();
		$state      = $connection->state();

		if ( CloudConnection::STATE_DISCONNECTED === $state ) {
			return CloudConfig::isConfigured()
				? __( 'Configured but not connected — no external calls', 'sentinel-forms' )
				: __( 'Not configured — no external calls', 'sentinel-forms' );
		}

		return sprintf(
			/* translators: 1: connection state, 2: site id */
			__( '%1$s (site %2$s)', 'sentinel-forms' ),
			$state,
			(string) $connection->siteId(),
		);
	}
}
