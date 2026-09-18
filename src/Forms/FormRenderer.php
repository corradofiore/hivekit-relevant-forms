<?php
/**
 * Front-end form rendering (doc §9).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms;

use RelevantForms\Forms\Fields\FieldTypeRegistry;
use RelevantForms\Forms\Fields\PaymentField;
use RelevantForms\Forms\Fields\TextareaField;
use RelevantForms\Forms\Visibility\VisibilityRules;

/**
 * Renders published forms as accessible markup and registers the shortcode.
 */
final class FormRenderer {

	/**
	 * Register the shortcode and front-end assets.
	 */
	public static function register(): void {
		add_shortcode( 'relevant_form', [ self::class, 'shortcode' ] );
	}

	/**
	 * Shortcode callback: [relevant_form id="123"].
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public static function shortcode( array|string $atts = [] ): string {
		$atts = shortcode_atts( [ 'id' => 0 ], is_array( $atts ) ? $atts : [], 'relevant_form' );

		return self::render( (int) $atts['id'] );
	}

	/**
	 * Render a published form by ID.
	 *
	 * @param int                          $formId Form ID.
	 * @param array<string, string>|null   $errors Validation errors keyed by field id.
	 * @param array<string, mixed>|null    $values Previously submitted values.
	 */
	public static function render( int $formId, ?array $errors = null, ?array $values = null ): string {
		$form = ( new FormRepository() )->findPublished( $formId );

		if ( null === $form ) {
			return '';
		}

		return self::renderForm( $form, $errors, $values );
	}

	/**
	 * Render a loaded form entity. Errors/values default to the front-end
	 * submission feedback of the current request (if any), so a failed
	 * in-page POST shows validation errors inline.
	 *
	 * @param Form                         $form   Form to render.
	 * @param array<string, string>|null   $errors Validation errors keyed by field id.
	 * @param array<string, mixed>|null    $values Previously submitted values.
	 */
	public static function renderForm( Form $form, ?array $errors = null, ?array $values = null ): string {
		if ( null === $errors ) {
			$errors = SubmissionFeedback::errors( (int) $form->id );
		}

		if ( null === $values ) {
			$values = SubmissionFeedback::values( (int) $form->id );
		}

		wp_enqueue_style( 'relevant-forms' );

		$hasFileField       = false;
		$hasLimitedTextarea = false;
		$hasVisibility      = false;
		$hasPaymentFields   = false;

		foreach ( $form->schema->fields() as $definition ) {
			$fieldType = (string) ( $definition['type'] ?? '' );

			if ( 'file' === $fieldType ) {
				$hasFileField = true;
			}

			if ( 'textarea' === $fieldType && TextareaField::limit( $definition ) > 0 ) {
				$hasLimitedTextarea = true;
			}

			if ( VisibilityRules::hasVisibility( $definition ) ) {
				$hasVisibility = true;
			}

			if ( FieldTypeRegistry::get( $fieldType ) instanceof PaymentField ) {
				$hasPaymentFields = true;
			}
		}

		if ( $hasFileField ) {
			wp_enqueue_script( 'relevant-forms-upload' );
		}

		if ( $hasLimitedTextarea ) {
			wp_enqueue_script( 'relevant-forms-counter' );
		}

		if ( $hasVisibility ) {
			wp_enqueue_script( 'relevant-forms-conditional' );
		}

		if ( $hasPaymentFields ) {
			wp_enqueue_script( 'relevant-forms-total' );
		}

		$output   = [];
		$output[] = self::successMessage( $form );
		$output[] = self::paymentNotice( $form );
		// Empty action: the browser posts to this very page, where the
		// front-end submission handler renders errors inline and redirects
		// after success — user pages never post to wp-admin.
		$output[] = sprintf(
			'<form class="relevant-forms" data-form-id="%d" data-upload-nonce="%s" method="post" action="" novalidate>',
			$form->id,
			esc_attr( wp_create_nonce( 'relevant_upload_' . $form->id ) )
		);
		$output[] = '<input type="hidden" name="action" value="relevant_forms_submit">';
		$output[] = sprintf( '<input type="hidden" name="_relevant_form_id" value="%d">', $form->id );
		$output[] = sprintf( '<input type="hidden" name="_relevant_started_at" value="%s">', esc_attr( (string) microtime( true ) ) );
		$output[] = wp_nonce_field( 'relevant_form_' . $form->id, '_relevant_form_nonce', true, false );
		$output[] = self::honeypot();

		$visibility = VisibilityRules::evaluate( $form->schema->fields(), $values );
		$currency   = $form->schema->currency();

		foreach ( $form->schema->fields() as $definition ) {
			// Payment fields format and validate with the form currency.
			$definition['currency'] = $currency;

			$type = FieldTypeRegistry::get( (string) ( $definition['type'] ?? '' ) );

			if ( null === $type ) {
				continue;
			}

			$fieldId = (string) ( $definition['id'] ?? '' );
			$value   = array_key_exists( $fieldId, $values ) ? $values[ $fieldId ] : ( $definition['default_value'] ?? '' );
			$error   = isset( $errors[ $fieldId ] ) ? (string) $errors[ $fieldId ] : null;

			if ( isset( $visibility[ $fieldId ] ) ) {
				$hidden = false === $visibility[ $fieldId ];

				// A hidden required field must not block a no-JS submission;
				// the script restores the flag when the field is revealed.
				if ( $hidden ) {
					$definition['visibility_required'] = ! empty( $definition['required'] );
					$definition['required']            = false;
				}

				$definition['visibility_hidden'] = $hidden;
			}

			$output[] = $type->render( $definition, $value, $error, (string) $form->id );
		}

		$output[] = sprintf(
			'<button type="submit" class="relevant-forms-submit">%s</button>',
			esc_html__( 'Submit', 'relevant-forms' )
		);
		$output[] = '</form>';

		return implode( "\n", $output );
	}

	/**
	 * Confirmation message when the URL carries a success marker for this form.
	 */
	private static function successMessage( Form $form ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success marker set by our own redirect.
		if ( ! isset( $_GET['relevant_form_success'] ) || (int) $_GET['relevant_form_success'] !== $form->id ) {
			return '';
		}

		$message = $form->schema->confirmationMessage();

		if ( '' === $message ) {
			$message = __( 'Thank you for your message. We will be in touch soon.', 'relevant-forms' );
		}

		return sprintf( '<p class="relevant-forms-success" role="status">%s</p>', esc_html( $message ) );
	}

	/**
	 * Notice when a payment attempt came back without completing (the
	 * checkout cancel URL or an expired session redirect carries this
	 * marker).
	 */
	private static function paymentNotice( Form $form ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only marker set by our own redirect.
		if ( ! isset( $_GET['relevant_payment_failed'] ) || (int) $_GET['relevant_payment_failed'] !== $form->id ) {
			return '';
		}

		return sprintf(
			'<p class="relevant-forms-payment-notice" role="alert">%s</p>',
			esc_html__( 'The payment was not completed. You can try again below.', 'relevant-forms' )
		);
	}

	/**
	 * Visually hidden honeypot field (doc §12). Bots filling it are dropped.
	 */
	private static function honeypot(): string {
		return '<div class="relevant-forms-hp" aria-hidden="true">'
			. '<label for="relevant-forms-hp">' . esc_html__( 'Website', 'relevant-forms' ) . '</label>'
			. '<input type="text" id="relevant-forms-hp" name="_relevant_hp" tabindex="-1" autocomplete="off">'
			. '</div>';
	}
}
