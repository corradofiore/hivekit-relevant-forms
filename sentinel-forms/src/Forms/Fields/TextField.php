<?php
/**
 * Single-line text field.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Forms\Fields;

/**
 * text input.
 */
final class TextField implements FieldType {

	/**
	 * Default maximum length for single-line text (used when the field
	 * definition does not carry a max_length; 0 in the definition = no limit).
	 */
	public const MAX_LENGTH = 500;

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'text';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Single-line text', 'sentinel-forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $definition, mixed $value ): ?string {
		$value = (string) $value;

		if ( '' === $value ) {
			return null;
		}

		$limit = self::limit( $definition );

		if ( $limit > 0 && mb_strlen( $value ) > $limit ) {
			return sprintf(
				/* translators: %d: maximum length */
				__( 'Text is too long (maximum %d characters).', 'sentinel-forms' ),
				$limit
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
		$limit   = self::limit( $definition );
		$control = sprintf(
			'<input type="text" name="%1$s" id="%2$s" value="%3$s" placeholder="%4$s"%5$s%6$s%7$s>',
			esc_attr( FieldMarkup::name( $definition ) ),
			esc_attr( FieldMarkup::id( $definition, $formId ) ),
			esc_attr( (string) $value ),
			esc_attr( (string) ( $definition['placeholder'] ?? '' ) ),
			! empty( $definition['required'] ) ? ' required' : '',
			! empty( $definition['help_text'] ) ? ' aria-describedby="' . esc_attr( FieldMarkup::id( $definition, $formId ) ) . '-help"' : '',
			$limit > 0 ? ' maxlength="' . esc_attr( (string) $limit ) . '"' : ''
		);

		return FieldMarkup::wrap( $definition, $formId, $control, $error );
	}

	/**
	 * Effective maximum length: the definition's max_length when present
	 * (0 = no limit), otherwise the type default.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	private static function limit( array $definition ): int {
		return array_key_exists( 'max_length', $definition )
			? max( 0, (int) $definition['max_length'] )
			: self::MAX_LENGTH;
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
			'max_length'    => self::MAX_LENGTH,
		];
	}
}
