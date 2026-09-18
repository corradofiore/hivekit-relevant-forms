<?php
/**
 * Payment: checkboxes field — priced multi-select.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * Checkbox group with priced options; the sum is the contribution.
 */
final class PaymentCheckboxField extends PaymentField {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'payment-checkbox';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Payment: checkboxes', 'relevant-forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $definition, mixed $value ): ?string {
		if ( is_array( $value ) ) {
			$positions = $value;
		} elseif ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
			$positions = [ $value ];
		} else {
			return null;
		}

		foreach ( $positions as $position ) {
			if ( null === $this->priceForPosition( $definition, $position ) ) {
				return __( 'A selected payment option is not available.', 'relevant-forms' );
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		return $this->labelsForPositions( $definition, $value );
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		$current = array_map( 'strval', is_array( $value ) ? $value : [] );
		$inputs  = [];

		foreach ( $this->pricedChoices( $definition ) as $choice ) {
			$inputs[] = sprintf(
				'<span class="relevant-forms-choice"><input type="checkbox" name="%1$s[]" id="%2$s-%3$s" value="%3$s"%4$s><label for="%2$s-%3$s">%5$s</label></span>',
				esc_attr( FieldMarkup::name( $definition ) ),
				esc_attr( FieldMarkup::id( $definition, $formId ) ),
				esc_attr( $choice['value'] ),
				in_array( $choice['value'], $current, true ) ? ' checked' : '',
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
