<?php
/**
 * Forms list admin page.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Pages;

use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormUsage;

/**
 * Lists forms with edit/duplicate/archive actions.
 */
final class FormListPage {

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		$forms = ( new FormRepository() )->all( [ 'limit' => 500 ] );
		$usage = FormUsage::map();

		require SENTINEL_FORMS_PATH . 'templates/admin/forms-list.php';
	}
}
