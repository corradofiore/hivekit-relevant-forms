<?php
/**
 * Shared button style builder for the website-builder integrations.
 *
 * Converts the button controls (background, text color, border radius,
 * padding) into a scoped CSS rule for the form's buttons and into the
 * wrapper markup that carries it. Every value is sanitized — invalid
 * colors or lengths are dropped instead of being written into the page —
 * and when no usable value is set the form passes through untouched, so
 * the buttons keep the theme's own styling.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Support;

/**
 * Sanitized CSS and wrapper markup for the form button controls.
 */
final class ButtonStyleCss {

	/**
	 * Build the scoped rule for one set of button values.
	 *
	 * @param string                $selector Button selector (already scoped to the instance).
	 * @param array<string, string> $values   Raw control values: 'background', 'text', 'radius', 'padding'.
	 * @return string CSS rule ('' when nothing usable is set).
	 */
	public static function css( string $selector, array $values ): string {
		return StyleCss::rule( $selector, self::declarations( $values ) );
	}

	/**
	 * Wrap rendered form markup in a deterministic scope class with its
	 * button styles. Identical values share one scope, and unstyled forms
	 * pass through unchanged — identical markup to the shortcode.
	 *
	 * @param string                $html   Rendered form markup.
	 * @param array<string, string> $values Raw control values: 'background', 'text', 'radius', 'padding'.
	 * @param string                $prefix Scope class prefix (e.g. "rf-bb-").
	 * @return string Wrapped markup (or the untouched markup).
	 */
	public static function scopedHtml( string $html, array $values, string $prefix ): string {
		return StyleCss::scopedHtml( $html, FieldSelectors::BUTTONS, self::declarations( $values ), $values, $prefix );
	}

	/**
	 * The sanitized declarations of one set of button values, in a fixed order.
	 *
	 * @param array<string, string> $values Raw control values.
	 * @return list<string> Declarations (empty when nothing usable is set).
	 */
	private static function declarations( array $values ): array {
		$declarations = [];

		$background = StyleCss::color( (string) ( $values['background'] ?? '' ) );
		if ( '' !== $background ) {
			$declarations[] = 'background-color:' . $background . ';';
		}

		$text = StyleCss::color( (string) ( $values['text'] ?? '' ) );
		if ( '' !== $text ) {
			$declarations[] = 'color:' . $text . ';';
		}

		$radius = StyleCss::length( (string) ( $values['radius'] ?? '' ) );
		if ( '' !== $radius ) {
			$declarations[] = 'border-radius:' . $radius . ';';
		}

		$padding = self::padding( (string) ( $values['padding'] ?? '' ) );
		if ( '' !== $padding ) {
			$declarations[] = 'padding:' . $padding . ';';
		}

		return $declarations;
	}

	/**
	 * A sanitized CSS color: hex, rgb()/rgba()/hsl()/hsla(), transparent or
	 * currentColor. Anything else (including declaration-breaking input) is
	 * dropped.
	 *
	 * @param string $raw Raw control value.
	 * @return string Color ('' when unset or invalid).
	 */
	public static function color( string $raw ): string {
		return StyleCss::color( $raw );
	}

	/**
	 * A sanitized CSS length: a number with an optional unit (unitless
	 * numbers count as pixels, mirroring the Beaver Builder controls).
	 *
	 * @param string $raw Raw control value.
	 * @return string Length ('' when unset or invalid).
	 */
	public static function length( string $raw ): string {
		return StyleCss::length( $raw );
	}

	/**
	 * A sanitized CSS padding shorthand of one to four length values.
	 *
	 * @param string $raw Raw control value (e.g. "12px 24px").
	 * @return string Padding shorthand ('' when unset or invalid).
	 */
	public static function padding( string $raw ): string {
		$raw = trim( (string) preg_replace( '/\s+/', ' ', $raw ) );

		if ( '' === $raw ) {
			return '';
		}

		$tokens = explode( ' ', $raw );

		if ( count( $tokens ) > 4 ) {
			return '';
		}

		$lengths = [];

		foreach ( $tokens as $token ) {
			$length = self::length( $token );

			if ( '' === $length ) {
				return '';
			}

			$lengths[] = $length;
		}

		return implode( ' ', $lengths );
	}
}
