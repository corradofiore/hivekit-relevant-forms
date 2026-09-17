<?php
/**
 * Multi-line text field.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * textarea.
 */
final class TextareaField implements FieldType {

	/**
	 * Default maximum length for multi-line text (used when the field
	 * definition does not carry a max_length; 0 in the definition = no limit).
	 */
	public const MAX_LENGTH = 10000;

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
		return __( 'Paragraph text', 'relevant-forms' );
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
				__( 'Text is too long (maximum %d characters).', 'relevant-forms' ),
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
		$used    = mb_strlen( (string) $value );
		$control = sprintf(
			'<textarea name="%1$s" id="%2$s" placeholder="%3$s" rows="4"%4$s%5$s>%6$s</textarea>',
			esc_attr( FieldMarkup::name( $definition ) ),
			esc_attr( FieldMarkup::id( $definition, $formId ) ),
			esc_attr( (string) ( $definition['placeholder'] ?? '' ) ),
			! empty( $definition['required'] ) ? ' required' : '',
			$limit > 0 ? ' maxlength="' . esc_attr( (string) $limit ) . '"' : '',
			esc_textarea( (string) $value )
		);

		// Realtime character counter ("28/500"), kept in sync by the
		// front-end script and by admin.js in the editor preview. Without a
		// limit there is no total: rendered hidden so the editor can reveal
		// it the moment a limit is configured.
		$control .= sprintf(
			'<p class="relevant-forms-char-count" data-rf-count-for="%1$s"%2$s>%3$s</p>',
			esc_attr( FieldMarkup::id( $definition, $formId ) ),
			$limit > 0 ? '' : ' hidden',
			esc_html( $limit > 0 ? $used . '/' . $limit : (string) $used )
		);

		return FieldMarkup::wrap( $definition, $formId, $control, $error );
	}

	/**
	 * Effective maximum length: the definition's max_length when present
	 * (0 = no limit), otherwise the type default.
	 *
	 * Public so the form renderer can decide whether the character counter
	 * script is needed for a form.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	public static function limit( array $definition ): int {
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
