<?php
/**
 * WPForms import admin page (doc §46–47).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Pages;

use RelevantForms\Import\WpFormsImporter;
use RelevantForms\Import\WpFormsScanner;

/**
 * Compatibility scan, per-form import and the last migration report.
 */
final class ImportPage {

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		$scan   = ( new WpFormsScanner() )->report();
		$report = WpFormsImporter::lastReport();

		require RELEVANT_FORMS_PATH . 'templates/admin/import.php';
	}
}
