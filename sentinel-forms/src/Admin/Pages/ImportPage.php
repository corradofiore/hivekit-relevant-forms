<?php
/**
 * WPForms import admin page (doc §46–47).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Pages;

use SentinelForms\Import\WpFormsImporter;
use SentinelForms\Import\WpFormsScanner;

/**
 * Compatibility scan, per-form import and the last migration report.
 */
final class ImportPage {

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		$scan   = ( new WpFormsScanner() )->report();
		$report = WpFormsImporter::lastReport();

		require SENTINEL_FORMS_PATH . 'templates/admin/import.php';
	}
}
