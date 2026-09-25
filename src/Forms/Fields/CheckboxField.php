<?php
/**
 * Checkbox group field.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * Multiple checkboxes; values stored as a list.
 */
final class CheckboxField implements FieldType, ChoiceFieldType {

	use ChoiceTrait;

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'checkbox';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Checkboxes', 'relevant-forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $definition, mixed $value ): ?string {
		$values = is_array( $value ) ? array_values( $value ) : [];

		if ( $values === [] ) {
			return null;
		}

		$allowed = $this->allowedValues( $definition );
		foreach ( $values as $item ) {
			if ( ! in_array( (string) $item, $allowed, true ) ) {
				return __( 'The selected value is not allowed.', 'relevant-forms' );
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		$values  = is_array( $value ) ? array_values( $value ) : [];
		$allowed = $this->allowedValues( $definition );

		return array_values(
			array_filter(
				array_map( 'strval', $values ),
				static fn ( string $item ): bool => in_array( $item, $allowed, true )
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		$values = is_array( $value ) ? array_map( 'strval', $value ) : [];

		$inputs = [];
		foreach ( $this->normalizeChoices( $definition ) as $index => $choice ) {
			$inputs[] = sprintf(
				'<span class="relevant-forms-choice"><input type="checkbox" name="%1$s[]" id="%2$s-%3$d" value="%4$s"%5$s><label for="%2$s-%3$d">%6$s</label></span>',
				esc_attr( FieldMarkup::name( $definition ) ),
				esc_attr( FieldMarkup::id( $definition, $formId ) ),
				$index,
				esc_attr( $choice['value'] ),
				checked( in_array( $choice['value'], $values, true ), true, false ),
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
