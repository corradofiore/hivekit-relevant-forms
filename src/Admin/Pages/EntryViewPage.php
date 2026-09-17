<?php
/**
 * Single entry view admin page.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Pages;

use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Events\EventRecorder;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Uploads\Domain\UploadRepository;

/**
 * Entry detail: values, metadata, events and status management.
 */
final class EntryViewPage {

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
		$entryId = isset( $_GET['entry_id'] ) ? absint( wp_unslash( $_GET['entry_id'] ) ) : 0;
		$entry   = $entryId > 0 ? ( new EntryRepository() )->find( $entryId ) : null;

		if ( null === $entry ) {
			wp_die( esc_html__( 'Entry not found.', 'relevant-forms' ), '', [ 'response' => 404 ] );
		}

		$form   = ( new FormRepository() )->find( $entry->formId );
		$events = EventRecorder::forEntry( $entry->id ?? 0 );

		$uploads = null !== $entry->id ? ( new UploadRepository() )->findForEntry( $entry->id ) : [];

		require RELEVANT_FORMS_PATH . 'templates/admin/entry-view.php';
	}
}
