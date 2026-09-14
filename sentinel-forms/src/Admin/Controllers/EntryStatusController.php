<?php
/**
 * Entry status controller (admin-post action).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Controllers;

use SentinelForms\Entries\Entry;
use SentinelForms\Entries\EntryRepository;
use SentinelForms\Events\EventRecorder;

/**
 * Changes an entry workflow status (doc §11, §26).
 */
final class EntryStatusController {

	/**
	 * Register the admin-post action.
	 */
	public static function register(): void {
		add_action( 'admin_post_sentinel_forms_entry_status', [ self::class, 'handle' ] );
	}

	/**
	 * Handle the status change request.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		$raw     = wp_unslash( $_POST );
		$entryId = isset( $raw['entry_id'] ) ? absint( $raw['entry_id'] ) : 0;
		$status  = sanitize_key( (string) ( $raw['entry_status'] ?? '' ) );

		if ( $entryId <= 0 || ! in_array( $status, Entry::STATUSES, true ) ) {
			wp_die( esc_html__( 'Invalid status.', 'sentinel-forms' ), '', [ 'response' => 400 ] );
		}

		check_admin_referer( 'sentinel_entry_status_' . $entryId );

		$repository = new EntryRepository();
		$updated    = $repository->updateStatus( $entryId, $status );

		if ( $updated ) {
			EventRecorder::record( $entryId, 'entry_status_changed', [ 'status' => $status ] );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=sentinel-entry&entry_id=' . $entryId . '&updated=1' ) );
		exit;
	}
}
