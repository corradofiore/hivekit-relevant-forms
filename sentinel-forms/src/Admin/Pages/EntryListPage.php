<?php
/**
 * Entries list admin page.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Pages;

use SentinelForms\Entries\Entry;
use SentinelForms\Entries\EntryRepository;

/**
 * Entry inbox with status filters (doc §60).
 */
final class EntryListPage {

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter parameter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( ! in_array( $status, Entry::STATUSES, true ) ) {
			$status = '';
		}

		$repository = new EntryRepository();
		$entries    = $repository->findMany(
			[ 'limit' => 200 ] + ( '' !== $status ? [ 'status' => $status ] : [] )
		);
		$counts     = [
			'total' => $repository->count(),
		];
		foreach ( Entry::STATUSES as $entryStatus ) {
			$counts[ $entryStatus ] = $repository->count( [ 'status' => $entryStatus ] );
		}

		require SENTINEL_FORMS_PATH . 'templates/admin/entries-list.php';
	}
}
