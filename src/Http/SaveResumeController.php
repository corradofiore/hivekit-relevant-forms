<?php
/**
 * Front-end Save and Resume endpoint (admin-post, JSON responses).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Http;

use RelevantForms\Entries\Resume\PartialSaveService;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRepository;

/**
 * Handles "Save and Resume" clicks from public forms: validates the per-form
 * nonce, stores a partial entry and triggers the resume-link email. Mirrors
 * the upload endpoints (admin-post, JSON envelopes, nonce in the payload).
 */
final class SaveResumeController {

	/**
	 * Register the public and logged-in endpoints.
	 */
	public static function register(): void {
		add_action( 'admin_post_nopriv_relevant_forms_save_resume', [ self::class, 'handle' ] );
		add_action( 'admin_post_relevant_forms_save_resume', [ self::class, 'handle' ] );
	}

	/**
	 * Handle a save request.
	 */
	public static function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below against the per-form nonce.
		$raw = wp_unslash( $_POST );

		$formId = isset( $raw['_relevant_form_id'] ) ? absint( $raw['_relevant_form_id'] ) : 0;

		if ( $formId <= 0 || ! isset( $raw['_relevant_resume_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) $raw['_relevant_resume_nonce'] ), 'relevant_save_resume_' . $formId ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'relevant-forms' ) ], 403 );
		}

		$form = ( new FormRepository() )->find( $formId );

		if ( null === $form || 'published' !== $form->status ) {
			wp_send_json_error( [ 'message' => __( 'Form not found.', 'relevant-forms' ) ], 404 );
		}

		$config = $form->schema->saveResume();

		/**
		 * Filters whether Save and Resume is active for a form.
		 *
		 * @param bool $enabled Whether the per-form toggle is on.
		 * @param Form $form    Form entity.
		 */
		if ( ! (bool) apply_filters( 'relevant_forms_save_resume_enabled', $config['enabled'], $form ) ) {
			wp_send_json_error( [ 'message' => __( 'Saving is not available for this form.', 'relevant-forms' ) ], 409 );
		}

		$email = sanitize_email( (string) ( $raw['save_resume_email'] ?? '' ) );
		$page  = isset( $raw['save_resume_page'] ) ? max( 1, absint( $raw['save_resume_page'] ) ) : 1;

		$token = isset( $raw['save_resume_token'] )
			? preg_replace( '/[^0-9a-f]/', '', strtolower( sanitize_text_field( (string) $raw['save_resume_token'] ) ) )
			: '';
		$token = is_string( $token ) ? $token : '';

		$sourceUrl = isset( $raw['save_resume_source_url'] ) ? esc_url_raw( (string) $raw['save_resume_source_url'] ) : '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- stored only; escaped on display.
		$referrer = (string) ( $_SERVER['HTTP_REFERER'] ?? '' );

		$result = ( new PartialSaveService() )->save( $form, $raw, $email, $page, $token, $sourceUrl, $referrer );

		if ( 'saved' !== $result['status'] ) {
			wp_send_json_error( [ 'message' => $result['message'] ], 'rate_limited' === $result['status'] ? 429 : 422 );
		}

		wp_send_json_success(
			[
				'message'    => $result['message'],
				'token'      => $result['token'],
				'expires_at' => $result['expires_at'],
				'emailed'    => $result['emailed'],
			]
		);
	}
}
