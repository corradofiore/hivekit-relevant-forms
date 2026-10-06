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
		$declarations = [];

		$background = self::color( (string) ( $values['background'] ?? '' ) );
		if ( '' !== $background ) {
			$declarations[] = 'background-color:' . $background . ';';
		}

		$text = self::color( (string) ( $values['text'] ?? '' ) );
		if ( '' !== $text ) {
			$declarations[] = 'color:' . $text . ';';
		}

		$radius = self::length( (string) ( $values['radius'] ?? '' ) );
		if ( '' !== $radius ) {
			$declarations[] = 'border-radius:' . $radius . ';';
		}

		$padding = self::padding( (string) ( $values['padding'] ?? '' ) );
		if ( '' !== $padding ) {
			$declarations[] = 'padding:' . $padding . ';';
		}

		if ( [] === $declarations ) {
			return '';
		}

		return $selector . '{' . implode( '', $declarations ) . '}';
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
		if ( '' === $html ) {
			return $html;
		}

		ksort( $values );

		$scope = $prefix . substr( md5( (string) wp_json_encode( $values ) ), 0, 12 );
		$css   = self::css( '.' . $scope . ' ' . FieldSelectors::BUTTONS, $values );

		if ( '' === $css ) {
			return $html;
		}

		return '<style>' . $css . '</style>'
			. '<div class="' . esc_attr( $scope ) . '">' . $html . '</div>';
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
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $raw ) ) {
			return $raw;
		}

		if ( preg_match( '/^(?:rgb|rgba|hsl|hsla)\([\d\s.,%\/]+\)$/i', $raw ) ) {
			return $raw;
		}

		if ( in_array( strtolower( $raw ), [ 'transparent', 'currentcolor' ], true ) ) {
			return $raw;
		}

		return '';
	}

	/**
	 * A sanitized CSS length: a number with an optional unit (unitless
	 * numbers count as pixels, mirroring the Beaver Builder controls).
	 *
	 * @param string $raw Raw control value.
	 * @return string Length ('' when unset or invalid).
	 */
	public static function length( string $raw ): string {
		$raw = strtolower( trim( $raw ) );

		if ( '' === $raw ) {
			return '';
		}

		if ( '0' === $raw || preg_match( '/^\d+(?:\.\d+)?$/', $raw ) ) {
			return '0' === $raw ? '0' : $raw . 'px';
		}

		if ( preg_match( '/^(\d+(?:\.\d+)?)(px|em|rem|%|vw|vh)$/', $raw, $matches ) ) {
			return $matches[1] . $matches[2];
		}

		return '';
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
