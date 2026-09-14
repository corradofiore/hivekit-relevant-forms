<?php
/**
 * Form editor admin page (create and edit).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Pages;

use SentinelForms\Forms\Fields\FieldTypeRegistry;
use SentinelForms\Forms\Form;
use SentinelForms\Forms\FormRepository;

/**
 * Renders the form builder: fields, confirmation and notification settings.
 */
final class FormEditPage {

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
		$formId = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;

		$form      = $formId > 0 ? ( new FormRepository() )->find( $formId ) : null;
		$types     = FieldTypeRegistry::all();
		$isEditing = null !== $form;

		if ( null === $form ) {
			$form = new Form( 0, '', 'draft', \SentinelForms\Forms\FormSchema::empty(), '', '' );
		}

		require SENTINEL_FORMS_PATH . 'templates/admin/form-edit.php';
	}
}
