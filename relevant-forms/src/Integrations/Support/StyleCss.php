<?php
/**
 * Shared CSS rule builder for the website-builder integrations.
 *
 * Both the button controls and the text controls turn sanitized values into
 * one scoped rule, so the sanitizers, the deterministic scope class and the
 * wrapper markup live here once. Identical values always produce the same
 * scope class, which is what keeps the Visual Builder preview and the front
 * end in step.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Support;

/**
 * Sanitizers, rule building and scoped wrapper markup.
 */
final class StyleCss {

	/**
	 * One CSS rule from a selector and its already-sanitized declarations.
	 *
	 * @param string       $selector     Full selector (already scoped to the instance).
	 * @param list<string> $declarations Sanitized declarations, e.g. "color:#112233;".
	 * @return string Rule ('' when no declaration is usable).
	 */
	public static function rule( string $selector, array $declarations ): string {
		if ( [] === $declarations ) {
			return '';
		}

		return $selector . '{' . implode( '', $declarations ) . '}';
	}

	/**
	 * Wrap rendered form markup in a deterministic scope class with one rule
	 * per selector. Identical values share one scope, and unstyled forms pass
	 * through unchanged — identical markup to the shortcode.
	 *
	 * @param string                       $html   Rendered form markup.
	 * @param array<string, list<string>>  $rules  Selector => sanitized declarations.
	 * @param array<string, mixed>         $values Raw control values (they decide the scope class).
	 * @param string                       $prefix Scope class prefix (e.g. "rf-bb-").
	 * @return string Wrapped markup (or the untouched markup).
	 */
	public static function scopedRules( string $html, array $rules, array $values, string $prefix ): string {
		if ( '' === $html ) {
			return $html;
		}

		ksort( $values );

		$scope = $prefix . substr( md5( (string) wp_json_encode( $values ) ), 0, 12 );
		$css   = '';

		foreach ( $rules as $selector => $declarations ) {
			$css .= self::rule( '.' . $scope . ' ' . (string) $selector, $declarations );
		}

		if ( '' === $css ) {
			return $html;
		}

		return '<style>' . $css . '</style>'
			. '<div class="' . esc_attr( $scope ) . '">' . $html . '</div>';
	}

	/**
	 * Wrap rendered form markup in a deterministic scope class with one rule
	 * for the given selector. Identical values share one scope, and unstyled
	 * forms pass through unchanged — identical markup to the shortcode.
	 *
	 * @param string                $html         Rendered form markup.
	 * @param string                $selector     Element selector, relative to the scope class.
	 * @param list<string>          $declarations Sanitized declarations.
	 * @param array<string, string> $values       Raw control values (they decide the scope class).
	 * @param string                $prefix       Scope class prefix (e.g. "rf-bb-").
	 * @return string Wrapped markup (or the untouched markup).
	 */
	public static function scopedHtml( string $html, string $selector, array $declarations, array $values, string $prefix ): string {
		return self::scopedRules( $html, [ $selector => $declarations ], $values, $prefix );
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
}
