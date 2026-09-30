<?php
/**
 * Logs admin page (doc §58).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Pages;

use RelevantForms\Admin\AdminMenu;
use RelevantForms\Support\Logger;

/**
 * Structured plugin logs viewer: level filter, message search, pagination.
 */
final class LogsPage {

	/**
	 * Entries per page.
	 */
	private const PER_PAGE = 20;

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		global $wpdb;

		$table  = $wpdb->prefix . 'relevant_logs';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( null === $exists ) {
			$missing = true;

			require RELEVANT_FORMS_PATH . 'templates/admin/logs.php';

			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view filters.
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$level  = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$search = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $level, Logger::LEVELS, true ) ) {
			$level = '';
		}

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		if ( '' !== $level && '' !== $search ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE level = %s AND message LIKE %s', $level, $like ) );
		} elseif ( '' !== $level ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE level = %s', $level ) );
		} elseif ( '' !== $search ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE message LIKE %s', $like ) );
		} else {
			$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
		}

		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged  = min( $paged, $pages );
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		if ( '' !== $level && '' !== $search ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, level, message, context, created_at FROM ' . $table . ' WHERE level = %s AND message LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d', $level, $like, self::PER_PAGE, $offset ) );
		} elseif ( '' !== $level ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, level, message, context, created_at FROM ' . $table . ' WHERE level = %s ORDER BY id DESC LIMIT %d OFFSET %d', $level, self::PER_PAGE, $offset ) );
		} elseif ( '' !== $search ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, level, message, context, created_at FROM ' . $table . ' WHERE message LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d', $like, self::PER_PAGE, $offset ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, level, message, context, created_at FROM ' . $table . ' ORDER BY id DESC LIMIT %d OFFSET %d', self::PER_PAGE, $offset ) );
		}

		$settings = (array) get_option( 'relevant_forms_settings', [] );
		$debugOn  = ! empty( $settings['debug_logging'] );
		$missing  = false;
		$first    = 0 === $total ? 0 : $offset + 1;
		$last     = $offset + count( (array) $rows );

		require RELEVANT_FORMS_PATH . 'templates/admin/logs.php';
	}
}
