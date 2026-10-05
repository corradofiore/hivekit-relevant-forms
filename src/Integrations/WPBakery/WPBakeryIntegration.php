<?php
/**
 * WPBakery Page Builder (Visual Composer) integration: registers the
 * "Relevant Form" element. The element's base matches the [relevant_form]
 * shortcode, which WPBakery maps automatically — so the element renders
 * through the very same code path as everything else.
 *
 * The hook only fires while WPBakery is active; nothing loads without it.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\WPBakery;

use RelevantForms\Integrations\Support\FormChoices;

/**
 * Registers the WPBakery element and its form picker.
 */
final class WPBakeryIntegration {

	/**
	 * Register the WPBakery hook.
	 */
	public static function register(): void {
		add_action( 'vc_before_init', [ self::class, 'registerElement' ] );
	}

	/**
	 * Map the element through vc_map(); WPBakery outputs the matching
	 * [relevant_form] shortcode on the front end.
	 */
	public static function registerElement(): void {
		if ( ! function_exists( 'vc_map' ) ) {
			return;
		}

		vc_map(
			[
				'name'        => esc_html__( 'Relevant Form', 'relevant-forms' ),
				'base'        => 'relevant_form',
				'category'    => esc_html__( 'Relevant Forms', 'relevant-forms' ),
				'description' => esc_html__( 'Embed a form built with Relevant Forms.', 'relevant-forms' ),
				'params'      => [
					[
						'type'        => 'dropdown',
						'heading'     => esc_html__( 'Form', 'relevant-forms' ),
						'param_name'  => 'id',
						'value'       => self::elementOptions(),
						'description' => esc_html__( 'Only published forms are rendered on the front end.', 'relevant-forms' ),
					],
				],
			]
		);
	}

	/**
	 * Dropdown options in WPBakery's label => value order. Duplicate labels
	 * get their form ID appended so every form stays selectable.
	 *
	 * @return array<string, string>
	 */
	public static function elementOptions(): array {
		$choices = FormChoices::all();

		$labelCounts = [];

		foreach ( $choices as $label ) {
			$labelCounts[ $label ] = ( $labelCounts[ $label ] ?? 0 ) + 1;
		}

		$options = [];

		foreach ( $choices as $id => $label ) {
			if ( '' === (string) $id ) {
				continue;
			}

			if ( $labelCounts[ $label ] > 1 ) {
				/* translators: 1: form name, 2: form ID. */
				$label = sprintf( __( '%1$s (ID %2$d)', 'relevant-forms' ), $label, $id );
			}

			$options[ $label ] = (string) $id;
		}

		return $options;
	}
}
