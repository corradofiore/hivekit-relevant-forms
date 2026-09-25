<?php
/**
 * Radio group field.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * Radio buttons with allowed-value enforcement.
 */
final class RadioField implements FieldType, ChoiceFieldType {

	use ChoiceTrait;

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'radio';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Radio buttons', 'relevant-forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $definition, mixed $value ): ?string {
		$value = (string) $value;

		if ( '' === $value ) {
			return null;
		}

		if ( ! in_array( $value, $this->allowedValues( $definition ), true ) ) {
			return __( 'The selected value is not allowed.', 'relevant-forms' );
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		$value = (string) $value;

		return in_array( $value, $this->allowedValues( $definition ), true ) ? $value : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		$inputs = [];
		foreach ( $this->normalizeChoices( $definition ) as $index => $choice ) {
			$inputs[] = sprintf(
				'<span class="relevant-forms-choice"><input type="radio" name="%1$s" id="%2$s-%3$d" value="%4$s"%5$s><label for="%2$s-%3$d">%6$s</label></span>',
				esc_attr( FieldMarkup::name( $definition ) ),
				esc_attr( FieldMarkup::id( $definition, $formId ) ),
				$index,
				esc_attr( $choice['value'] ),
				checked( (string) $value, $choice['value'], false ),
				esc_html( $choice['label'] )
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
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return [
			'required'  => false,
			'help_text' => '',
			'choices'   => [],
		];
	}
}
