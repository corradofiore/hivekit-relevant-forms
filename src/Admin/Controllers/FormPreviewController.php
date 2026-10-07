<?php
/**
 * Form preview AJAX handler (form editor).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Controllers;

use RelevantForms\Admin\Preview\FormPreview;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Support\Ajax;

/**
 * Re-renders the Form preview tab after a save, so the editor shows the
 * stored form without reloading the page (which would drop unsaved edits).
 */
final class FormPreviewController {

	/**
	 * AJAX action (also the nonce action).
	 */
	public const ACTION = 'relevant_forms_preview_form';

	/**
	 * Register the AJAX handler.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'render' ] );
	}

	/**
	 * AJAX: render the preview body of one form.
	 */
	public static function render(): void {
		Ajax::ensureManager( __( 'You do not have permission to preview forms.', 'relevant-forms' ) );

		check_ajax_referer( self::ACTION );

		$formId = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$form   = $formId > 0 ? ( new FormRepository() )->find( $formId ) : null;

		if ( ! $form instanceof Form ) {
			Ajax::fail( 'not_found', __( 'Save the form before refreshing the preview.', 'relevant-forms' ), 404 );
		}

		wp_send_json_success( [ 'html' => FormPreview::body( $form ) ] );
	}
}
