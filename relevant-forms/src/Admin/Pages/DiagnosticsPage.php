<?php
/**
 * Diagnostics admin page (doc §58).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Pages;

use RelevantForms\Admin\Controllers\ApiStatusController;

/**
 * Environment overview and on-demand API status test.
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

		$apiAction = ApiStatusController::ACTION;
		$apiNonce  = wp_create_nonce( ApiStatusController::ACTION );

		require RELEVANT_FORMS_PATH . 'templates/admin/diagnostics.php';
	}
}
