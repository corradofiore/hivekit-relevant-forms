<?php
/**
 * Consent checkbox field.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * Single mandatory checkbox; the label carries the consent text.
 */
final class ConsentField implements FieldType {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'consent';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Consent checkbox', 'relevant-forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $definition, mixed $value ): ?string {
		$value = (string) $value;

		if ( '' !== $value && '1' !== $value ) {
			return __( 'Invalid consent value.', 'relevant-forms' );
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		return '1' === (string) $value ? '1' : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		$control = sprintf(
			'<span class="relevant-forms-choice"><input type="checkbox" name="%1$s" id="%2$s" value="1"%3$s%4$s><label for="%2$s">%5$s</label></span>',
			esc_attr( FieldMarkup::name( $definition ) ),
			esc_attr( FieldMarkup::id( $definition, $formId ) ),
			checked( '1', (string) $value, false ),
			! empty( $definition['required'] ) ? ' required' : '',
			esc_html( (string) ( $definition['label'] ?? '' ) )
		);

		return sprintf(
			'<div class="relevant-forms-field relevant-forms-field--consent">%s%s</div>',
			$control,
			FieldMarkup::error( $definition, $formId, $error )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return [
			'required'  => true,
			'help_text' => '',
		];
	}
}
