<?php
/**
 * Elementor integration: registers the "Relevant Form" widget.
 *
 * Both hooks only fire while Elementor is active, so on sites without
 * Elementor nothing is loaded.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Elementor;

use RelevantForms\Forms\FormRenderer;
use RelevantForms\Integrations\Support\FormChoices;

/**
 * Hooks the widget into Elementor and provides the data it needs.
 */
final class ElementorIntegration {

	/**
	 * Register the Elementor hooks.
	 */
	public static function register(): void {
		add_action( 'elementor/widgets/register', [ self::class, 'registerWidget' ] );
		add_action( 'elementor/elements/categories_registered', [ self::class, 'registerCategory' ] );
	}

	/**
	 * Register the widget with Elementor's widget manager.
	 *
	 * @param object|null $widgets_manager Elementor widgets manager.
	 */
	public static function registerWidget( $widgets_manager = null ): void {
		if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		require_once __DIR__ . '/FormWidget.php';

		$widgets_manager->register( new FormWidget() );
	}

	/**
	 * Add the "Relevant Forms" category to the widget panel.
	 *
	 * @param object|null $elements_manager Elementor elements manager.
	 */
	public static function registerCategory( $elements_manager = null ): void {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			'relevant-forms',
			[
				'title' => esc_html__( 'Relevant Forms', 'relevant-forms' ),
				'icon'  => 'eicon-form-horizontal',
			]
		);
	}

	/**
	 * Widget control options: the shared choices with a leading "Select a
	 * form" placeholder.
	 *
	 * @return array<int|string, string>
	 */
	public static function formOptions(): array {
		return FormChoices::withPlaceholder();
	}

	/**
	 * Render the chosen form exactly like the [relevant_form] shortcode.
	 *
	 * @param int $formId Form ID.
	 */
	public static function renderForm( int $formId ): string {
		return FormRenderer::render( $formId );
	}
}
