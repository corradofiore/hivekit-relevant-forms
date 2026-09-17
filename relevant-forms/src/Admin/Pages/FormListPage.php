<?php
/**
 * Forms list admin page.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Pages;

use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormUsage;

/**
 * Lists forms with edit/duplicate/archive actions.
 */
final class FormListPage {

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		$forms = ( new FormRepository() )->all( [ 'limit' => 500 ] );
		$usage = FormUsage::map();

		require RELEVANT_FORMS_PATH . 'templates/admin/forms-list.php';
	}
}
