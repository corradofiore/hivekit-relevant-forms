<?php
/**
 * Diagnostics admin page (doc §58).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Pages;

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
		];

		$dbVersion = (string) get_option( 'sentinel_forms_db_version', '0' );
		$settings  = (array) get_option( 'sentinel_forms_settings', [] );

		// Provider statuses are placeholders until their phases land.
		$providers = [
			'AI provider'      => __( 'Not configured', 'sentinel-forms' ),
			'SMS provider'     => __( 'Not configured', 'sentinel-forms' ),
			'Storage provider' => __( 'Not configured', 'sentinel-forms' ),
			'Malware scanner'  => __( 'Not configured', 'sentinel-forms' ),
		];

		require SENTINEL_FORMS_PATH . 'templates/admin/diagnostics.php';
	}
}
