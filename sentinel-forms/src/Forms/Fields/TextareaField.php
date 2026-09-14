<?php
/**
 * Multi-line text field.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Forms\Fields;

/**
 * textarea.
 */
final class TextareaField implements FieldType {

	/**
	 * Max length for multi-line text.
	 */
	private const MAX_LENGTH = 10000;

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'textarea';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Paragraph text', 'sentinel-forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $definition, mixed $value ): ?string {
		$value = (string) $value;

		if ( '' === $value ) {
			return null;
		}

		if ( mb_strlen( $value ) > self::MAX_LENGTH ) {
			return sprintf(
				/* translators: %d: maximum length */
				__( 'Text is too long (maximum %d characters).', 'sentinel-forms' ),
				self::MAX_LENGTH
			);
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
			'<textarea name="%1$s" id="%2$s" placeholder="%3$s" rows="4"%4$s>%5$s</textarea>',
			esc_attr( FieldMarkup::name( $definition ) ),
			esc_attr( FieldMarkup::id( $definition, $formId ) ),
			esc_attr( (string) ( $definition['placeholder'] ?? '' ) ),
			! empty( $definition['required'] ) ? ' required' : '',
			esc_textarea( (string) $value )
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
