<?php
/**
 * Form bulk actions controller (duplicate, archive).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Controllers;

use RelevantForms\Forms\FormRepository;

/**
 * Handles duplicate/archive actions from the forms list.
 *
 * Row actions are nonce-signed links (native list-table pattern), so the
 * request parameters may arrive as GET on admin-post.php.
 */
final class FormActionController {

	/**
	 * Register the admin-post action.
	 */
	public static function register(): void {
		add_action( 'admin_post_relevant_forms_action', [ self::class, 'handle' ] );
	}

	/**
	 * Handle the action request.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		$raw    = wp_unslash( $_REQUEST );
		$action = sanitize_key( (string) ( $raw['form_action'] ?? '' ) );
		$formId = isset( $raw['form_id'] ) ? absint( $raw['form_id'] ) : 0;

		if ( ! in_array( $action, [ 'duplicate', 'archive' ], true ) || $formId <= 0 ) {
			wp_die( esc_html__( 'Invalid action.', 'relevant-forms' ), '', [ 'response' => 400 ] );
		}

		check_admin_referer( 'relevant_form_action_' . $action . '_' . $formId );

		$repository = new FormRepository();

		if ( 'duplicate' === $action ) {
			$repository->duplicate( $formId );
		} else {
			$repository->archive( $formId );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=relevant-forms&updated=1' ) );
		exit;
	}
}
