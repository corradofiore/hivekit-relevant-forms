<?php
/**
 * Single entry view admin page.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Pages;

use SentinelForms\Entries\Entry;
use SentinelForms\Entries\EntryRepository;
use SentinelForms\Events\EventRecorder;
use SentinelForms\Forms\FormRepository;

/**
 * Entry detail: values, metadata, events and status management.
 */
final class EntryViewPage {

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
		$entryId = isset( $_GET['entry_id'] ) ? absint( wp_unslash( $_GET['entry_id'] ) ) : 0;
		$entry   = $entryId > 0 ? ( new EntryRepository() )->find( $entryId ) : null;

		if ( null === $entry ) {
			wp_die( esc_html__( 'Entry not found.', 'sentinel-forms' ), '', [ 'response' => 404 ] );
		}

		$form   = ( new FormRepository() )->find( $entry->formId );
		$events = EventRecorder::forEntry( $entry->id ?? 0 );

		require SENTINEL_FORMS_PATH . 'templates/admin/entry-view.php';
	}
}
