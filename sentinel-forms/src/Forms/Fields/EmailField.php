<?php
/**
 * Email field.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Forms\Fields;

/**
 * email input.
 */
final class EmailField implements FieldType {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'email';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Email', 'sentinel-forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $definition, mixed $value ): ?string {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		if ( filter_var( $value, FILTER_VALIDATE_EMAIL ) === false ) {
			return __( 'Please enter a valid email address.', 'sentinel-forms' );
		}

		return null;
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
		$control = sprintf(
			'<input type="email" name="%1$s" id="%2$s" value="%3$s" placeholder="%4$s"%5$s>',
			esc_attr( FieldMarkup::name( $definition ) ),
			esc_attr( FieldMarkup::id( $definition, $formId ) ),
			esc_attr( (string) $value ),
			esc_attr( (string) ( $definition['placeholder'] ?? '' ) ),
			! empty( $definition['required'] ) ? ' required' : ''
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
			'required'      => false,
			'placeholder'   => '',
			'help_text'     => '',
			'default_value' => '',
		];
	}
}
