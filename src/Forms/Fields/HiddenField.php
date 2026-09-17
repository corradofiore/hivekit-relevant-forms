<?php
/**
 * Hidden field.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * Non-visible field carrying a fixed value (doc §7.1).
 */
final class HiddenField implements FieldType {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'hidden';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Hidden field', 'relevant-forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $definition, mixed $value ): ?string {
		$value = (string) $value;

		if ( '' === $value || mb_strlen( $value ) <= 500 ) {
			return null;
		}

		return __( 'Hidden field value is too long.', 'relevant-forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		return trim( (string) $value );
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		$current = '' !== (string) $value ? (string) $value : (string) ( $definition['default_value'] ?? '' );

		return sprintf(
			'<input type="hidden" name="%1$s" id="%2$s" value="%3$s">',
			esc_attr( FieldMarkup::name( $definition ) ),
			esc_attr( FieldMarkup::id( $definition, $formId ) ),
			esc_attr( $current )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return [
			'required'      => false,
			'default_value' => '',
		];
	}
}
