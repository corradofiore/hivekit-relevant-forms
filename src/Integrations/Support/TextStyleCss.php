<?php
/**
 * Shared text style builder for the website-builder integrations.
 *
 * One call styles the three text groups independently: field labels (plus the
 * display headings), captions (help text, step indicator, empty state) and
 * option labels (radio, checkbox and consent text). Each group takes the same
 * three values — color, font size, font weight — and a group with no value
 * emits no rule, so the theme's own typography (bold labels, smaller grey
 * captions) stays in place until an owner changes it.
 *
 * The scope class is derived from every group's values, so identical settings
 * render identical markup and the Visual Builder preview always matches the
 * front end.
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
	 * Build one rule per group and concatenate them ('' when no group has a
	 * usable value).
	 *
	 * @param string                               $scope  Instance scope selector (e.g. ".rf-bb-ab12…").
	 * @param array<string, array<string, string>> $groups Raw control values per group selector.
	 * @return string CSS ('' when nothing is set).
	 */
	public static function css( string $scope, array $groups ): string {
		$css = '';

		foreach ( self::rules( self::normalized( $groups ) ) as $selector => $declarations ) {
			$css .= StyleCss::rule( $scope . ' ' . (string) $selector, $declarations );
		}

		return $css;
	}

	/**
	 * Wrap rendered form markup in one deterministic scope class carrying
	 * every group's rule. Identical values share one scope, and unstyled
	 * forms pass through unchanged — identical markup to the shortcode.
	 *
	 * @param string                               $html   Rendered form markup.
	 * @param array<string, array<string, string>> $groups Raw control values per group selector.
	 * @param string                               $prefix Scope class prefix (e.g. "rf-gb-").
	 * @return string Wrapped markup (or the untouched markup).
	 */
	public static function scopedHtml( string $html, array $groups, string $prefix ): string {
		$groups = self::normalized( $groups );
		$rules  = self::rules( $groups );

		// The scope class is derived from the rules that actually render, so
		// a control left empty (or a key no builder sets) cannot change the
		// markup of an otherwise identical module.
		return StyleCss::scopedRules( $html, $rules, $rules, $prefix );
	}

	/**
	 * The three text groups with empty values, ready for a builder to fill in
	 * from its own control names.
	 *
	 * @return array<string, array<string, string>> Group selector => empty values.
	 */
	public static function emptyGroups(): array {
		return [
			FieldSelectors::LABELS   => self::emptyValues(),
			FieldSelectors::CAPTIONS => self::emptyValues(),
			FieldSelectors::OPTIONS  => self::emptyValues(),
		];
	}

	/**
	 * Pick one group's values out of a flat source array of control values.
	 *
	 * @param array<string, mixed>  $source Raw control values (settings, attributes, …).
	 * @param array<string, string> $keys   Map of style key => source key.
	 * @return array<string, string> {color,size,weight} values.
	 */
	public static function group( array $source, array $keys ): array {
		$values = self::emptyValues();

		foreach ( $keys as $key => $sourceKey ) {
			$value          = $source[ $sourceKey ] ?? '';
			$values[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $values;
	}

	/**
	 * The sanitized declarations per group, in a fixed order.
	 *
	 * @param array<string, array<string, string>> $groups Raw control values per group selector.
	 * @return array<string, list<string>> Selector => declarations.
	 */
	private static function rules( array $groups ): array {
		$rules = [];

		foreach ( $groups as $selector => $values ) {
			$declarations = self::declarations( is_array( $values ) ? $values : [] );

			if ( [] !== $declarations ) {
				$rules[ (string) $selector ] = $declarations;
			}
		}

		return $rules;
	}

	/**
	 * The sanitized declarations of one group of values, in a fixed order.
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
	 * The groups in the plugin's own order (labels, captions, options) with
	 * sorted keys, so identical values always produce identical CSS and the
	 * same scope class no matter which order a builder filled them in.
	 *
	 * @param array<string, array<string, string>> $groups Raw control values per group selector.
	 * @return array<string, array<string, string>> Normalized groups.
	 */
	private static function normalized( array $groups ): array {
		$sorted = [];

		foreach ( $groups as $selector => $values ) {
			$group = is_array( $values ) ? $values : [];

			ksort( $group );

			$sorted[ (string) $selector ] = $group;
		}

		$ordered = [];

		foreach ( array_keys( self::emptyGroups() ) as $selector ) {
			if ( isset( $sorted[ $selector ] ) ) {
				$ordered[ $selector ] = $sorted[ $selector ];
				unset( $sorted[ $selector ] );
			}
		}

		// Anything else (a builder with its own selectors) follows, sorted.
		ksort( $sorted );

		return $ordered + $sorted;
	}

	/**
	 * One empty group of values.
	 *
	 * @return array<string, string> Group values.
	 */
	private static function emptyValues(): array {
		return [
			'color'  => '',
			'size'   => '',
			'weight' => '',
		];
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
