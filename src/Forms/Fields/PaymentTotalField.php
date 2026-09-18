<?php
/**
 * Payment: total field — display-only running total.
 *
 * The authoritative amount is always recomputed server-side at submit
 * (PaymentTotals); this control only shows it. frontend JS updates the text
 * live as priced choices change; without JS the visitor still pays the
 * correct server-computed total.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

use RelevantForms\Payments\Money;

/**
 * Display-only total line.
 */
final class PaymentTotalField extends PaymentField {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'payment-total';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Payment: total', 'relevant-forms' );
	}

	/**
	 * {@inheritDoc} Display-only: nothing to validate.
	 */
	public function validate( array $definition, mixed $value ): ?string {
		return null;
	}

	/**
	 * {@inheritDoc} Carries no value.
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		$control = sprintf(
			'<p class="relevant-forms-payment-total" id="%1$s" data-rf-payment-total="1">%2$s</p>',
			esc_attr( FieldMarkup::id( $definition, $formId ) ),
			esc_html( Money::format( 0, $this->currency( $definition ) ) )
		);

		return FieldMarkup::wrap( $definition, $formId, $control, $error );
	}

	/**
	 * {@inheritDoc} The total reflects other fields; it contributes nothing.
	 */
	public function contributionMinor( array $definition, mixed $raw ): int {
		return 0;
	}
}
