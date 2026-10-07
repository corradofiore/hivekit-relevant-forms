<?php
/**
 * Shared text style builder for the website-builder integrations.
 *
 * Converts the "Text styles" controls (color, font size, font weight) into a
 * scoped rule for the text that sits outside the form controls: field
 * labels, option labels, help text and the display headings. The form
 * controls keep their own "Field styles" controls, so the two are styled
 * independently. When no value is set the form passes through untouched and
 * the theme keeps its typography.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Support;

/**
 * Sanitized CSS and wrapper markup for the form's non-control text.
 */
final class TextStyleCss {

	/**
	 * Build the scoped rule for one set of text values.
	 *
	 * @param string                $selector Text selector (already scoped to the instance).
	 * @param array<string, string> $values   Raw control values: 'color', 'size', 'weight'.
	 * @return string CSS rule ('' when nothing usable is set).
	 */
	public static function css( string $selector, array $values ): string {
		return StyleCss::rule( $selector, self::declarations( $values ) );
	}

	/**
	 * Wrap rendered form markup in a deterministic scope class with its text
	 * styles. Identical values share one scope, and unstyled forms pass
	 * through unchanged — identical markup to the shortcode.
	 *
	 * @param string                $html   Rendered form markup.
	 * @param array<string, string> $values Raw control values: 'color', 'size', 'weight'.
	 * @param string                $prefix Scope class prefix (e.g. "rf-bb-").
	 * @return string Wrapped markup (or the untouched markup).
	 */
	public static function scopedHtml( string $html, array $values, string $prefix ): string {
		return StyleCss::scopedHtml( $html, FieldSelectors::TEXT, self::declarations( $values ), $values, $prefix );
	}

	/**
	 * The sanitized declarations of one set of text values, in a fixed order.
	 *
	 * @param array<string, string> $values Raw control values.
	 * @return list<string> Declarations (empty when nothing usable is set).
	 */
	private static function declarations( array $values ): array {
		$declarations = [];

		$color = StyleCss::color( (string) ( $values['color'] ?? '' ) );
		if ( '' !== $color ) {
			$declarations[] = 'color:' . $color . ';';
		}

		$size = StyleCss::length( (string) ( $values['size'] ?? '' ) );
		if ( '' !== $size ) {
			$declarations[] = 'font-size:' . $size . ';';
		}

		$weight = self::weight( (string) ( $values['weight'] ?? '' ) );
		if ( '' !== $weight ) {
			$declarations[] = 'font-weight:' . $weight . ';';
		}

		return $declarations;
	}

	/**
	 * A sanitized font weight: the numeric scale (100–900) or the two named
	 * keywords WordPress themes use. Anything else is dropped.
	 *
	 * @param string $raw Raw control value.
	 * @return string Weight ('' when unset or invalid).
	 */
	public static function weight( string $raw ): string {
		$raw = strtolower( trim( $raw ) );

		if ( '' === $raw ) {
			return '';
		}

		if ( in_array( $raw, [ 'normal', 'bold' ], true ) ) {
			return $raw;
		}

		if ( preg_match( '/^\d{3}$/', $raw ) ) {
			$value = (int) $raw;

			if ( $value >= 100 && $value <= 900 ) {
				return (string) $value;
			}
		}

		return '';
	}
}
