<?php
/**
 * Payment: dropdown field — priced choices, submits the choice position.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * Dropdown with priced options.
 */
final class PaymentSelectField extends PaymentField {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'payment-select';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Payment: dropdown', 'relevant-forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $definition, mixed $value ): ?string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return null;
		}

		if ( null === $this->priceForPosition( $definition, $value ) ) {
			return __( 'This payment option is not available.', 'relevant-forms' );
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		$labels = $this->labelsForPositions( $definition, $value );

		return $labels[0] ?? '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		$options = '<option value="">' . esc_html__( '— Select —', 'relevant-forms' ) . '</option>';

		foreach ( $this->pricedChoices( $definition ) as $choice ) {
			$options .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $choice['value'] ),
				selected( (string) $value, $choice['value'], false ),
				esc_html( $this->optionLabel( $definition, $choice['label'], $choice['priceMinor'] ) )
			);
		}

		$control = sprintf(
			'<select name="%1$s" id="%2$s"%3$s>%4$s</select>',
			esc_attr( FieldMarkup::name( $definition ) ),
			esc_attr( FieldMarkup::id( $definition, $formId ) ),
			! empty( $definition['required'] ) ? ' required' : '',
			$options
		);

		return FieldMarkup::wrap( $definition, $formId, $control, $error );
	}

	/**
	 * {@inheritDoc}
	 */
	public function contributionMinor( array $definition, mixed $raw ): int {
		return $this->sumPositionsMinor( $definition, $raw );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return parent::defaults() + [
			'choices' => [],
		];
	}
}
