<?php
/**
 * Front-end form rendering (doc §9).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms;

use RelevantForms\Forms\Fields\ChoiceFieldType;
use RelevantForms\Forms\Fields\FieldTypeRegistry;
use RelevantForms\Forms\Fields\PaymentField;
use RelevantForms\Forms\Fields\TextareaField;
use RelevantForms\Forms\Visibility\VisibilityRules;
use RelevantForms\Payments\Money;

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

		$definitions = $form->schema->fields();
		$visibility  = VisibilityRules::evaluate( $definitions, $values );
		$definitions = self::prepareDefinitions( $definitions, $visibility, $values, $form->schema->currency() );
		$pages       = self::groupPages( $definitions, $visibility );

		$hasFileField       = false;
		$hasLimitedTextarea = false;
		$hasVisibility      = false;
		$hasPaymentFields   = false;
		$hasEntryPreview    = false;

		foreach ( $definitions as $definition ) {
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

			if ( 'entry-preview' === $fieldType ) {
				$hasEntryPreview = true;
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

		if ( null !== $pages ) {
			wp_enqueue_script( 'relevant-forms-pages' );
		}

		if ( $hasEntryPreview ) {
			wp_enqueue_script( 'relevant-forms-preview' );
		}

		$output   = [];
		$output[] = self::successMessage( $form );
		$output[] = self::paymentNotice( $form );
		// Empty action: the browser posts to this very page, where the
		// front-end submission handler renders errors inline and redirects
		// after success — user pages never post to wp-admin.
		$pagesAttr = null === $pages
			? ''
			: sprintf(
				' data-rf-page-prev="%1$s" data-rf-page-next="%2$s" data-rf-page-step="%3$s"',
				esc_attr__( 'Previous', 'relevant-forms' ),
				esc_attr__( 'Next', 'relevant-forms' ),
				/* translators: 1: current page number, 2: total number of pages. */
				esc_attr__( 'Step %1$d of %2$d', 'relevant-forms' )
			);
		$output[] = sprintf(
			'<form class="relevant-forms" data-form-id="%d" data-upload-nonce="%s"%s method="post" action="" novalidate>',
			$form->id,
			esc_attr( wp_create_nonce( 'relevant_upload_' . $form->id ) ),
			$pagesAttr
		);
		$output[] = '<input type="hidden" name="action" value="relevant_forms_submit">';
		$output[] = sprintf( '<input type="hidden" name="_relevant_form_id" value="%d">', $form->id );
		$output[] = sprintf( '<input type="hidden" name="_relevant_started_at" value="%s">', esc_attr( (string) microtime( true ) ) );
		$output[] = wp_nonce_field( 'relevant_form_' . $form->id, '_relevant_form_nonce', true, false );
		$output[] = self::honeypot();

		$currency = $form->schema->currency();

		if ( null === $pages ) {
			foreach ( $definitions as $definition ) {
				$markup = self::renderField( $definition, $currency, $values, $errors, $visibility, (string) $form->id );

				if ( '' !== $markup ) {
					$output[] = $markup;
				}
			}

			$output[] = sprintf(
				'<button type="submit" class="relevant-forms-submit">%s</button>',
				esc_html__( 'Submit', 'relevant-forms' )
			);
		} else {
			$total = count( $pages );
			$break = FieldTypeRegistry::get( 'pagebreak' );

			foreach ( $pages as $index => $page ) {
				$output[] = sprintf( '<div class="relevant-forms-page" data-rf-page="%d">', $index + 1 );

				// The break marker opens every page after the first; it is a
				// plain divider and disappears together with its page.
				if ( $index > 0 && null !== $break && null !== $page['break'] ) {
					$output[] = $break->render( (array) $page['break'], null, null, (string) $form->id );
				}
				$output[] = sprintf(
					'<p class="relevant-forms-page-indicator" data-rf-page-indicator>%s</p>',
					esc_html(
						sprintf(
							/* translators: 1: current page number, 2: total number of pages. */
							__( 'Step %1$d of %2$d', 'relevant-forms' ),
							$index + 1,
							$total
						)
					)
				);

				$title = (string) ( $page['title'] ?? '' );

				if ( '' !== $title ) {
					$output[] = sprintf( '<h3 class="relevant-forms-page-title">%s</h3>', esc_html( $title ) );
				}

				foreach ( $page['fields'] as $definition ) {
					$markup = self::renderField( $definition, $currency, $values, $errors, $visibility, (string) $form->id );

					if ( '' !== $markup ) {
						$output[] = $markup;
					}
				}

				$output[] = '</div>';
			}

			$output[] = '<div class="relevant-forms-submit-row" data-rf-submit-row>';
			$output[] = sprintf(
				'<button type="submit" class="relevant-forms-submit">%s</button>',
				esc_html__( 'Submit', 'relevant-forms' )
			);
			$output[] = '</div>';
		}
		$output[] = '</form>';

		return implode( "\n", $output );
	}

	/**
	 * Render one field with its visibility state applied.
	 *
	 * @param array<string, mixed>   $definition Field definition.
	 * @param string                 $currency   Form currency.
	 * @param array<string, mixed>   $values     Submitted values keyed by field id.
	 * @param array<string, string>  $errors     Validation errors keyed by field id.
	 * @param array<string, bool>    $visibility Visibility map keyed by field id.
	 * @param string                 $formId     Form id for control ids.
	 */
	private static function renderField( array $definition, string $currency, array $values, array $errors, array $visibility, string $formId ): string {
		// Payment fields format and validate with the form currency.
		$definition['currency'] = $currency;

		$type = FieldTypeRegistry::get( (string) ( $definition['type'] ?? '' ) );

		if ( null === $type ) {
			return '';
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

		return $type->render( $definition, $value, $error, $formId );
	}

	/**
	 * Group definitions into pages at page breaks.
	 *
	 * Returns null when the form has no visible page breaks, so ordinary
	 * forms keep the original single-page markup. Hidden page breaks do not
	 * start a page (their fields flow on); pages that end up empty merge
	 * away.
	 *
	 * @param array<int, array<string, mixed>> $definitions Field definitions in order.
	 * @param array<string, bool>              $visibility  Visibility map keyed by field id.
	 * @return array<int, array{break: array<string, mixed>|null, title: string, fields: array<int, array<string, mixed>>}>|null
	 */
	private static function groupPages( array $definitions, array $visibility ): ?array {
		$hasBreak = false;

		foreach ( $definitions as $definition ) {
			if ( 'pagebreak' !== (string) ( $definition['type'] ?? '' ) ) {
				continue;
			}

			$fieldId = (string) ( $definition['id'] ?? '' );

			if ( isset( $visibility[ $fieldId ] ) && false === $visibility[ $fieldId ] ) {
				continue;
			}

			$hasBreak = true;
			break;
		}

		if ( ! $hasBreak ) {
			return null;
		}

		$raw = [
			[
				'break'  => null,
				'fields' => [],
			],
		];

		foreach ( $definitions as $definition ) {
			if ( 'pagebreak' === (string) ( $definition['type'] ?? '' ) ) {
				$fieldId = (string) ( $definition['id'] ?? '' );

				if ( isset( $visibility[ $fieldId ] ) && false === $visibility[ $fieldId ] ) {
					continue;
				}

				$raw[] = [
					'break'  => $definition,
					'fields' => [],
				];

				continue;
			}

			$raw[ count( $raw ) - 1 ]['fields'][] = $definition;
		}

		$pages = [];

		foreach ( $raw as $page ) {
			if ( [] === $page['fields'] ) {
				continue;
			}

			$page['title'] = trim( (string) ( $page['break']['label'] ?? '' ) );
			$pages[]       = $page;
		}

		// A single page (for example a trailing break with no fields after it)
		// is not a wizard; keep the plain single-page markup.
		if ( count( $pages ) < 2 ) {
			return null;
		}

		return $pages;
	}

	/**
	 * Inject payloads into definitions before rendering.
	 *
	 * Entry preview rows are computed here (never earlier) so both the plain
	 * and the paginated paths receive them. Definitions without preview
	 * fields pass through untouched.
	 *
	 * @param array<int, array<string, mixed>> $definitions Field definitions in order.
	 * @param array<string, bool>              $visibility  Visibility map keyed by field id.
	 * @param array<string, mixed>             $values      Entered values keyed by field id.
	 * @param string                           $currency    Form currency.
	 * @return array<int, array<string, mixed>>
	 */
	private static function prepareDefinitions( array $definitions, array $visibility, array $values, string $currency ): array {
		$hasPreview = false;

		foreach ( $definitions as $definition ) {
			if ( 'entry-preview' === (string) ( $definition['type'] ?? '' ) ) {
				$hasPreview = true;
				break;
			}
		}

		if ( ! $hasPreview ) {
			return $definitions;
		}

		foreach ( $definitions as $index => $definition ) {
			if ( 'entry-preview' !== (string) ( $definition['type'] ?? '' ) ) {
				continue;
			}

			$definitions[ $index ]['preview_rows'] = self::previewRows( $definitions, $index, $visibility, $values, $currency );
		}

		return $definitions;
	}

	/**
	 * Rows for one entry preview field: the data fields entered before it.
	 *
	 * Empty values and fields hidden by conditional logic keep their row but
	 * start hidden, so the front-end script can reveal them without a server
	 * round trip. Fields between two previews are only reported once, on the
	 * later preview, mirroring WPForms.
	 *
	 * @param array<int, array<string, mixed>> $definitions Field definitions in order.
	 * @param int                              $index       Preview field index.
	 * @param array<string, bool>              $visibility  Visibility map keyed by field id.
	 * @param array<string, mixed>             $values      Entered values keyed by field id.
	 * @param string                           $currency    Form currency.
	 * @return array<int, array{id: string, label: string, value: string, hidden_by_rule: bool}>
	 */
	private static function previewRows( array $definitions, int $index, array $visibility, array $values, string $currency ): array {
		$start = 0;

		for ( $i = $index - 1; $i >= 0; --$i ) {
			if ( 'entry-preview' === (string) ( $definitions[ $i ]['type'] ?? '' ) ) {
				$start = $i + 1;
				break;
			}
		}

		$rows = [];

		foreach ( array_slice( $definitions, $start, $index - $start ) as $definition ) {
			$type  = (string) ( $definition['type'] ?? '' );
			$id    = (string) ( $definition['id'] ?? '' );
			$label = trim( (string) ( $definition['label'] ?? '' ) );

			if ( in_array( $type, [ 'section', 'pagebreak', 'entry-preview' ], true ) || '' === $id || '' === $label ) {
				continue;
			}

			$rows[] = [
				'id'             => $id,
				'label'          => $label,
				'value'          => self::previewValue( $type, $definition, $values[ $id ] ?? '', $currency ),
				'hidden_by_rule' => false === ( $visibility[ $id ] ?? true ),
			];
		}

		return $rows;
	}

	/**
	 * Human-readable preview text for one entered value.
	 *
	 * Choice values resolve to their labels, priced choices to the same
	 * "Label (price)" text the visitor picked, and payment amounts to the
	 * form currency. The live total is left empty on purpose: the front-end
	 * script reads it from the total element.
	 *
	 * @param string               $type       Field type.
	 * @param array<string, mixed> $definition Field definition.
	 * @param mixed                $raw        Entered value.
	 * @param string               $currency   Form currency.
	 */
	private static function previewValue( string $type, array $definition, mixed $raw, string $currency ): string {
		if ( 'consent' === $type ) {
			return ! empty( $raw ) ? __( 'Yes', 'relevant-forms' ) : '';
		}

		if ( 'payment-total' === $type ) {
			return '';
		}

		if ( 'payment-single' === $type ) {
			$amount = is_scalar( $raw ) ? trim( (string) $raw ) : '';
			$minor  = '' !== $amount ? Money::toMinor( $amount, $currency ) : null;

			return null !== $minor ? Money::format( $minor, $currency ) : $amount;
		}

		$isPayment = in_array( $type, [ 'payment-select', 'payment-multiple', 'payment-checkbox' ], true );
		$isChoice  = $isPayment || in_array( $type, [ 'select', 'radio', 'checkbox' ], true );

		if ( is_array( $raw ) ) {
			$labels = [];

			foreach ( $raw as $item ) {
				if ( ! is_scalar( $item ) ) {
					continue;
				}

				$labels[] = $isChoice ? self::previewChoiceLabel( $definition, (string) $item, $isPayment, $currency ) : (string) $item;
			}

			return implode( ', ', $labels );
		}

		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$value = trim( (string) $raw );

		if ( '' === $value ) {
			return '';
		}

		return $isChoice ? self::previewChoiceLabel( $definition, $value, $isPayment, $currency ) : $value;
	}

	/**
	 * Label for a submitted choice value (with its price when priced).
	 *
	 * Priced payment fields submit the option position, everything else
	 * submits the option value (falling back to the label, like the entry
	 * storage).
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $value      Submitted value (or position).
	 * @param bool                 $priced     Whether this is a priced payment field.
	 * @param string               $currency   Form currency.
	 */
	private static function previewChoiceLabel( array $definition, string $value, bool $priced, string $currency ): string {
		$type = FieldTypeRegistry::get( (string) ( $definition['type'] ?? '' ) );

		if ( ! $type instanceof ChoiceFieldType ) {
			return $value;
		}

		$choices = $type->normalizeChoices( $definition );

		if ( $priced ) {
			$index = (int) $value;

			if ( ! isset( $choices[ $index ] ) ) {
				return $value;
			}

			$label = $choices[ $index ]['label'];
			$minor = Money::toMinor( $choices[ $index ]['value'], $currency );

			return null !== $minor && $minor > 0 ? $label . ' (' . Money::format( $minor, $currency ) . ')' : $label;
		}

		foreach ( $choices as $choice ) {
			if ( (string) $choice['value'] === $value ) {
				return $choice['label'];
			}
		}

		return $value;
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
