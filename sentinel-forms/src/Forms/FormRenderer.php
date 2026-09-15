<?php
/**
 * Front-end form rendering (doc §9).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Forms;

use SentinelForms\Forms\Fields\FieldTypeRegistry;

/**
 * Renders published forms as accessible markup and registers the shortcode.
 */
final class FormRenderer {

	/**
	 * Register the shortcode and front-end assets.
	 */
	public static function register(): void {
		add_shortcode( 'sentinel_form', [ self::class, 'shortcode' ] );
	}

	/**
	 * Shortcode callback: [sentinel_form id="123"].
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public static function shortcode( array|string $atts = [] ): string {
		$atts = shortcode_atts( [ 'id' => 0 ], is_array( $atts ) ? $atts : [], 'sentinel_form' );

		return self::render( (int) $atts['id'] );
	}

	/**
	 * Render a published form by ID.
	 *
	 * @param int                   $formId Form ID.
	 * @param array<string, string> $errors Validation errors keyed by field id.
	 * @param array<string, mixed>  $values Previously submitted values.
	 */
	public static function render( int $formId, array $errors = [], array $values = [] ): string {
		$form = ( new FormRepository() )->findPublished( $formId );

		if ( null === $form ) {
			return '';
		}

		return self::renderForm( $form, $errors, $values );
	}

	/**
	 * Render a loaded form entity.
	 *
	 * @param Form                  $form   Form to render.
	 * @param array<string, string> $errors Validation errors keyed by field id.
	 * @param array<string, mixed>  $values Previously submitted values.
	 */
	public static function renderForm( Form $form, array $errors = [], array $values = [] ): string {
		wp_enqueue_style( 'sentinel-forms' );

		$hasFileField = false;

		foreach ( $form->schema->fields() as $definition ) {
			if ( 'file' === (string) ( $definition['type'] ?? '' ) ) {
				$hasFileField = true;
				break;
			}
		}

		if ( $hasFileField ) {
			wp_enqueue_script( 'sentinel-forms-upload' );
		}

		$output   = [];
		$output[] = self::successMessage( $form );
		$output[] = sprintf(
			'<form class="sentinel-forms" data-form-id="%d" data-upload-nonce="%s" method="post" action="%s" novalidate>',
			$form->id,
			esc_attr( wp_create_nonce( 'sentinel_upload_' . $form->id ) ),
			esc_url( admin_url( 'admin-post.php' ) )
		);
		$output[] = '<input type="hidden" name="action" value="sentinel_forms_submit">';
		$output[] = sprintf( '<input type="hidden" name="_sentinel_form_id" value="%d">', $form->id );
		$output[] = sprintf( '<input type="hidden" name="_sentinel_started_at" value="%s">', esc_attr( (string) microtime( true ) ) );
		$output[] = wp_nonce_field( 'sentinel_form_' . $form->id, '_sentinel_form_nonce', true, false );
		$output[] = self::honeypot();

		foreach ( $form->schema->fields() as $definition ) {
			$type = FieldTypeRegistry::get( (string) ( $definition['type'] ?? '' ) );

			if ( null === $type ) {
				continue;
			}

			$fieldId = (string) ( $definition['id'] ?? '' );
			$value   = array_key_exists( $fieldId, $values ) ? $values[ $fieldId ] : ( $definition['default_value'] ?? '' );
			$error   = isset( $errors[ $fieldId ] ) ? (string) $errors[ $fieldId ] : null;

			$output[] = $type->render( $definition, $value, $error, (string) $form->id );
		}

		$output[] = sprintf(
			'<button type="submit" class="sentinel-forms-submit">%s</button>',
			esc_html__( 'Submit', 'sentinel-forms' )
		);
		$output[] = '</form>';

		return implode( "\n", $output );
	}

	/**
	 * Confirmation message when the URL carries a success marker for this form.
	 */
	private static function successMessage( Form $form ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success marker set by our own redirect.
		if ( ! isset( $_GET['sentinel_form_success'] ) || (int) $_GET['sentinel_form_success'] !== $form->id ) {
			return '';
		}

		$message = $form->schema->confirmationMessage();

		if ( '' === $message ) {
			$message = __( 'Thank you for your message. We will be in touch soon.', 'sentinel-forms' );
		}

		return sprintf( '<p class="sentinel-forms-success" role="status">%s</p>', esc_html( $message ) );
	}

	/**
	 * Visually hidden honeypot field (doc §12). Bots filling it are dropped.
	 */
	private static function honeypot(): string {
		return '<div class="sentinel-forms-hp" aria-hidden="true">'
			. '<label for="sentinel-forms-hp">' . esc_html__( 'Website', 'sentinel-forms' ) . '</label>'
			. '<input type="text" id="sentinel-forms-hp" name="_sentinel_hp" tabindex="-1" autocomplete="off">'
			. '</div>';
	}
}
