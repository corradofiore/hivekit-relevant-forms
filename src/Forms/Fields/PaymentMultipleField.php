<?php
/**
 * Payment: multiple items field — priced radio choices.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * Radio group with priced options.
 */
final class PaymentMultipleField extends PaymentField {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'payment-multiple';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Payment: multiple items', 'relevant-forms' );
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
		$inputs = [];

		foreach ( $this->pricedChoices( $definition ) as $choice ) {
			$inputs[] = sprintf(
				'<span class="relevant-forms-choice"><input type="radio" name="%1$s" id="%2$s-%3$s" value="%3$s"%4$s><label for="%2$s-%3$s">%5$s</label></span>',
				esc_attr( FieldMarkup::name( $definition ) ),
				esc_attr( FieldMarkup::id( $definition, $formId ) ),
				esc_attr( $choice['value'] ),
				checked( (string) $value, $choice['value'], false ),
				esc_html( $this->optionLabel( $definition, $choice['label'], $choice['priceMinor'] ) )
			);
		}

		$control = sprintf(
			'<fieldset class="relevant-forms-choice-group"%1$s>%2$s</fieldset>',
			! empty( $definition['required'] ) ? ' aria-required="true"' : '',
			implode( '', $inputs )
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
