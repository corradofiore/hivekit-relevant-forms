<?php
/**
 * Dropdown (select) field.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Forms\Fields;

/**
 * select with allowed-value enforcement.
 */
final class SelectField implements FieldType {

	use ChoiceTrait;

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'select';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Dropdown', 'sentinel-forms' );
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
			return __( 'The selected value is not allowed.', 'sentinel-forms' );
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
		$options = '<option value="">' . esc_html__( '— Select —', 'sentinel-forms' ) . '</option>';

		foreach ( $this->normalizeChoices( $definition ) as $choice ) {
			$options .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $choice['value'] ),
				selected( (string) $value, $choice['value'], false ),
				esc_html( $choice['label'] )
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
