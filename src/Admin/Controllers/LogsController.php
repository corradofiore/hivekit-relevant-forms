<?php
/**
 * Logs admin actions.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Controllers;

use RelevantForms\Admin\AdminMenu;

/**
 * Clears the plugin log table.
 *
 * The button is a nonce-signed link (native list-table pattern), so the
 * request arrives as GET on admin-post.php.
 */
final class LogsController {

	/**
	 * Register the admin-post action.
	 */
	public static function register(): void {
		add_action( 'admin_post_relevant_forms_clear_logs', [ self::class, 'handle' ] );
	}

	/**
	 * Handle the clear request: capability + nonce, delete every entry,
	 * redirect back with a notice marker.
	 */
	public static function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'relevant_forms_clear_logs' );

		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'relevant_logs' );

		wp_safe_redirect( admin_url( 'admin.php?page=relevant-logs&cleared=1' ) );
		exit;
	}
}
