<?php
/**
 * Shared escaping helpers for field markup.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Forms\Fields;

use SentinelForms\Forms\Visibility\VisibilityRules;

/**
 * Small static factory for consistent, escaped field markup.
 */
final class FieldMarkup {

	/**
	 * DOM id for a field instance.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	public static function id( array $definition, string $formId ): string {
		return 'sentinel-field-' . $formId . '-' . (string) ( $definition['id'] ?? '' );
	}

	/**
	 * Input name for a field.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	public static function name( array $definition ): string {
		return 'sentinel_field_' . (string) ( $definition['id'] ?? '' );
	}

	/**
	 * Label element.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	public static function label( array $definition, string $formId ): string {
		$label = (string) ( $definition['label'] ?? '' );

		if ( '' === $label ) {
			return '';
		}

		$required = ! empty( $definition['required'] ) ? ' <span class="sentinel-forms-required">*</span>' : '';

		return sprintf(
			'<label for="%1$s">%2$s%3$s</label>',
			esc_attr( self::id( $definition, $formId ) ),
			esc_html( $label ),
			$required
		);
	}

	/**
	 * Help text element.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	public static function help( array $definition, string $formId ): string {
		$help = (string) ( $definition['help_text'] ?? '' );

		if ( '' === $help ) {
			return '';
		}

		return sprintf(
			'<p class="sentinel-forms-help" id="%s-help">%s</p>',
			esc_attr( self::id( $definition, $formId ) ),
			esc_html( $help )
		);
	}

	/**
	 * Error element (announced by assistive technology).
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $formId     Form ID.
	 * @param string|null          $error      Error message.
	 */
	public static function error( array $definition, string $formId, ?string $error ): string {
		if ( null === $error || '' === $error ) {
			return '';
		}

		return sprintf(
			'<p class="sentinel-forms-error" id="%s-error" role="alert">%s</p>',
			esc_attr( self::id( $definition, $formId ) ),
			esc_html( $error )
		);
	}

	/**
	 * Wrapper around a control with label, help and error.
	 *
	 * Fields carrying visibility rules get the normalized configuration as
	 * a data attribute (frontend-conditional.js re-evaluates from it) and a
	 * `hidden` attribute when the renderer computed them as hidden. The
	 * optional `visibility_hidden` and `visibility_required` definition keys
	 * are render-time markers and are never persisted.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $control    Escaped control markup.
	 */
	public static function wrap( array $definition, string $formId, string $control, ?string $error = null ): string {
		$attributes = '';
		$visibility = VisibilityRules::normalize( $definition['visibility'] ?? null );

		if ( [] !== $visibility ) {
			$attributes = sprintf(
				' data-sf-visibility="%1$s"%2$s%3$s',
				esc_attr( (string) wp_json_encode( $visibility ) ),
				! empty( $definition['required'] ) || ! empty( $definition['visibility_required'] ) ? ' data-sf-required="1"' : '',
				! empty( $definition['visibility_hidden'] ) ? ' hidden' : ''
			);
		}

		return sprintf(
			'<div class="sentinel-forms-field sentinel-forms-field--%1$s"%2$s>%3$s%4$s%5$s%6$s</div>',
			esc_attr( (string) ( $definition['type'] ?? 'unknown' ) ),
			$attributes,
			self::label( $definition, $formId ),
			$control,
			self::help( $definition, $formId ),
			self::error( $definition, $formId, $error )
		);
	}
}
