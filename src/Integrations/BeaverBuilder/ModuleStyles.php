<?php
/**
 * Beaver Builder field styling: the "Field styles" settings section and the
 * scoped CSS it produces.
 *
 * Beaver Builder renders modules through the settings object; there is no
 * stylesheet step for declarative modules, so the CSS is emitted at render
 * time and scoped to the module instance through a generated class. The
 * controls mirror the Divi design groups: rounded corners, padding, border,
 * colors and font size — all targeting the form's input fields, not the
 * module wrapper.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\BeaverBuilder;

use RelevantForms\Integrations\Support\FieldSelectors;

/**
 * Settings schema and CSS builder for the module's field style controls.
 */
final class ModuleStyles {

	/**
	 * Units offered by the border radius control.
	 */
	private const RADIUS_UNITS = [ 'px', '%' ];

	/**
	 * Units offered by the length controls that accept font-relative sizes.
	 */
	private const TEXT_UNITS = [ 'px', 'em', 'rem' ];

	/**
	 * The style tab definition for Beaver Builder's settings form.
	 *
	 * @return array<string, mixed> Settings-form tab.
	 */
	public static function styleTab(): array {
		return [
			'title'    => esc_html__( 'Style', 'relevant-forms' ),
			'sections' => [
				'field_styles' => [
					'title'  => esc_html__( 'Field styles', 'relevant-forms' ),
					'fields' => self::fields(),
				],
			],
		];
	}

	/**
	 * Render the module: the form markup wrapped in a scope class plus the
	 * scoped `<style>` block. Returns '' when no form is selected or the
	 * form is not published, mirroring the shortcode.
	 *
	 * @param object $settings Module settings object.
	 * @return string Module output.
	 */
	public static function render( object $settings ): string {
		$values = get_object_vars( $settings );

		$form_html = BeaverIntegration::renderForm( (int) ( $values['form_id'] ?? 0 ) );

		if ( '' === $form_html ) {
			return '';
		}

		$scope_class = self::scopeClass( $values );
		$css         = self::css( $values, '.' . $scope_class );
		$style       = '' === $css ? '' : '<style>' . $css . '</style>';

		return $style . '<div class="' . esc_attr( $scope_class ) . '">' . $form_html . '</div>';
	}

	/**
	 * All settings fields for the "Field styles" section.
	 *
	 * @return array<string, array<string, mixed>> Settings fields.
	 */
	private static function fields(): array {
		$refresh = [ 'preview' => [ 'type' => 'refresh' ] ];

		return [
			'field_bg_color'          => $refresh + [
				'type'        => 'color',
				'label'       => esc_html__( 'Field background', 'relevant-forms' ),
				'default'     => '',
				'show_reset'  => true,
				'show_alpha'  => true,
				'connections' => [ 'color' ],
			],
			'field_text_color'        => $refresh + [
				'type'        => 'color',
				'label'       => esc_html__( 'Field text color', 'relevant-forms' ),
				'default'     => '',
				'show_reset'  => true,
				'show_alpha'  => true,
				'connections' => [ 'color' ],
			],
			'field_placeholder_color' => $refresh + [
				'type'        => 'color',
				'label'       => esc_html__( 'Placeholder color', 'relevant-forms' ),
				'default'     => '',
				'show_reset'  => true,
				'show_alpha'  => true,
				'connections' => [ 'color' ],
			],
			'field_border_width'      => $refresh + [
				'type'    => 'unit',
				'label'   => esc_html__( 'Border width', 'relevant-forms' ),
				'default' => '',
				'units'   => [ 'px' ],
			],
			'field_border_style'      => $refresh + [
				'type'    => 'select',
				'label'   => esc_html__( 'Border style', 'relevant-forms' ),
				'default' => 'solid',
				'options' => [
					'solid'  => esc_html__( 'Solid', 'relevant-forms' ),
					'dashed' => esc_html__( 'Dashed', 'relevant-forms' ),
					'dotted' => esc_html__( 'Dotted', 'relevant-forms' ),
				],
			],
			'field_border_color'      => $refresh + [
				'type'        => 'color',
				'label'       => esc_html__( 'Border color', 'relevant-forms' ),
				'default'     => '',
				'show_reset'  => true,
				'show_alpha'  => true,
				'connections' => [ 'color' ],
			],
			'field_radius'            => $refresh + [
				'type'    => 'unit',
				'label'   => esc_html__( 'Rounded corners', 'relevant-forms' ),
				'default' => '',
				'units'   => self::RADIUS_UNITS,
			],
			'field_padding'           => $refresh + [
				'type'    => 'dimension',
				'label'   => esc_html__( 'Padding', 'relevant-forms' ),
				'default' => '',
				'units'   => self::TEXT_UNITS,
			],
			'field_font_size'         => $refresh + [
				'type'    => 'unit',
				'label'   => esc_html__( 'Font size', 'relevant-forms' ),
				'default' => '',
				'units'   => self::TEXT_UNITS,
			],
		];
	}

	/**
	 * Build the scoped CSS for one module instance.
	 *
	 * @param array<string, mixed> $values Module settings as an array.
	 * @param string               $scope  Instance scope selector (e.g. ".rf-bb-ab12…").
	 * @return string CSS ('' when no style value is set).
	 */
	private static function css( array $values, string $scope ): string {
		$selector = $scope . ' ' . FieldSelectors::FIELDS;
		$css      = '';

		$declarations = [];

		$background = self::color( $values, 'field_bg_color' );
		if ( '' !== $background ) {
			$declarations[] = 'background-color:' . $background . ';';
		}

		$text = self::color( $values, 'field_text_color' );
		if ( '' !== $text ) {
			$declarations[] = 'color:' . $text . ';';
		}

		$border_width = self::length( $values, 'field_border_width', 'px' );
		$border_color = self::color( $values, 'field_border_color' );

		if ( '' !== $border_width ) {
			$declarations[] = 'border-width:' . $border_width . ';';
			$declarations[] = 'border-style:' . self::border_style( $values ) . ';';
		}

		if ( '' !== $border_color ) {
			$declarations[] = 'border-color:' . $border_color . ';';
		}

		$radius = self::length( $values, 'field_radius', 'px' );
		if ( '' !== $radius ) {
			$declarations[] = 'border-radius:' . $radius . ';';
		}

		$padding = self::padding( $values );
		if ( '' !== $padding ) {
			$declarations[] = 'padding:' . $padding . ';';
		}

		$font_size = self::length( $values, 'field_font_size', 'px' );
		if ( '' !== $font_size ) {
			$declarations[] = 'font-size:' . $font_size . ';';
		}

		if ( [] !== $declarations ) {
			$css .= $selector . '{' . implode( '', $declarations ) . '}';
		}

		$placeholder = self::color( $values, 'field_placeholder_color' );
		if ( '' !== $placeholder ) {
			$css .= $scope . ' ' . FieldSelectors::FIELDS_PLACEHOLDER . '{color:' . $placeholder . ';}';
		}

		return $css;
	}

	/**
	 * The four padding sides joined into one shorthand value. Empty sides
	 * become zero so a partially filled control still produces valid CSS.
	 *
	 * @param array<string, mixed> $values Module settings as an array.
	 * @return string Padding shorthand ('' when no side is set).
	 */
	private static function padding( array $values ): string {
		$sides = [];
		$any   = false;

		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			$key   = 'field_padding_' . $side;
			$value = trim( (string) ( $values[ $key ] ?? '' ) );

			if ( '' !== $value && is_numeric( $value ) ) {
				$sides[] = $value . self::unit( $values, 'field_padding', 'px', self::TEXT_UNITS );
				$any     = true;
				continue;
			}

			$sides[] = '0';
		}

		return $any ? implode( ' ', $sides ) : '';
	}

	/**
	 * A sanitized numeric length value with its unit (e.g. "16px").
	 *
	 * @param array<string, mixed> $values   Module settings as an array.
	 * @param string               $key      Setting name (unit stored at "{$key}_unit").
	 * @param string               $fallback Unit when none is stored.
	 * @param list<string>         $allowed  Accepted units.
	 * @return string Value with unit ('' when the setting is empty or not numeric).
	 */
	private static function length( array $values, string $key, string $fallback, array $allowed = [ 'px' ] ): string {
		$value = trim( (string) ( $values[ $key ] ?? '' ) );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}

		return $value . self::unit( $values, $key, $fallback, $allowed );
	}

	/**
	 * The stored unit for a numeric setting, validated against the allowed list.
	 *
	 * @param array<string, mixed> $values   Module settings as an array.
	 * @param string               $key      Setting name (unit stored at "{$key}_unit").
	 * @param string               $fallback Unit when none is stored.
	 * @param list<string>         $allowed  Accepted units.
	 * @return string Unit.
	 */
	private static function unit( array $values, string $key, string $fallback, array $allowed ): string {
		$unit = trim( (string) ( $values[ $key . '_unit' ] ?? '' ) );

		return in_array( $unit, $allowed, true ) ? $unit : $fallback;
	}

	/**
	 * The stored border style, limited to the offered values.
	 *
	 * @param array<string, mixed> $values Module settings as an array.
	 * @return string Border style.
	 */
	private static function border_style( array $values ): string {
		$style = trim( (string) ( $values['field_border_style'] ?? '' ) );

		return in_array( $style, [ 'solid', 'dashed', 'dotted' ], true ) ? $style : 'solid';
	}

	/**
	 * A sanitized CSS color: hex, rgb()/rgba()/hsl()/hsla() or transparent.
	 * Anything else (including declaration-breaking input) is dropped.
	 *
	 * @param array<string, mixed> $values Module settings as an array.
	 * @param string               $key    Setting name.
	 * @return string Color ('' when unset or invalid).
	 */
	private static function color( array $values, string $key ): string {
		$raw = trim( (string) ( $values[ $key ] ?? '' ) );

		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $raw ) ) {
			return $raw;
		}

		if ( preg_match( '/^(?:rgb|rgba|hsl|hsla)\([\d\s.,%\/]+\)$/i', $raw ) ) {
			return $raw;
		}

		if ( 'transparent' === strtolower( $raw ) ) {
			return $raw;
		}

		return '';
	}

	/**
	 * Deterministic per-instance class: identical settings render identical
	 * markup (and CSS), different settings get different scopes.
	 *
	 * @param array<string, mixed> $values Module settings as an array.
	 * @return string Class name.
	 */
	private static function scopeClass( array $values ): string {
		return 'rf-bb-' . substr( md5( (string) wp_json_encode( $values ) ), 0, 12 );
	}
}
