<?php
/**
 * Date field.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Forms\Fields;

use DateTimeImmutable;

/**
 * date input with strict Y-m-d validation.
 */
final class DateField implements FieldType {

	/**
	 * Accepted date format.
	 */
	private const FORMAT = 'Y-m-d';

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'date';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Date', 'sentinel-forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $definition, mixed $value ): ?string {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat( self::FORMAT, $value );

		if ( false === $date || $date->format( self::FORMAT ) !== $value ) {
			return __( 'Please enter a valid date.', 'sentinel-forms' );
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
			'<input type="date" name="%1$s" id="%2$s" value="%3$s"%4$s>',
			esc_attr( FieldMarkup::name( $definition ) ),
			esc_attr( FieldMarkup::id( $definition, $formId ) ),
			esc_attr( (string) $value ),
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
			'help_text'     => '',
			'default_value' => '',
		];
	}
}
