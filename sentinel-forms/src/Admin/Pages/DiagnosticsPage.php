<?php
/**
 * Diagnostics admin page (doc §58).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Pages;

use SentinelForms\Uploads\Scanning\BackendScanConfig;
use SentinelForms\Uploads\Scanning\ClamAvScanner;
use SentinelForms\Uploads\Scanning\ScannerFactory;
use SentinelForms\Uploads\Storage\StorageConfig;
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
			'AI provider'       => __( 'Not configured', 'sentinel-forms' ),
			'SMS provider'      => __( 'Not configured', 'sentinel-forms' ),
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
		if ( 's3' === StorageConfig::provider() ) {
			return StorageConfig::isS3Configured()
				? sprintf(
					/* translators: 1: bucket name, 2: endpoint */
					__( 'S3-compatible (bucket %1$s on %2$s)', 'sentinel-forms' ),
					StorageConfig::bucket(),
					StorageConfig::endpoint(),
				)
				: __( 'S3-compatible selected, not configured (falling back to local)', 'sentinel-forms' );
		}

		return __( 'Local private directory', 'sentinel-forms' );
	}

	/**
	 * Human-readable malware scanner status.
	 */
	private static function scannerStatus(): string {
		$scanner = ScannerFactory::make();
		$parts   = [ __( 'Basic (pure PHP) — always available', 'sentinel-forms' ) ];

		if ( $scanner instanceof ClamAvScanner ) {
			$parts[] = $scanner->isAvailable()
				? __( 'local ClamAV available (deep fallback)', 'sentinel-forms' )
				: __( 'local ClamAV unavailable (deep fallback)', 'sentinel-forms' );
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
