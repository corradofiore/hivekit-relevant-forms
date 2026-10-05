<?php
/**
 * Divi Builder integration: registers the "Relevant Form" module with the
 * Divi module picker (Divi 4 fields API, carried forward by Divi 5).
 *
 * The whole integration hangs off `et_builder_ready`, which only fires while
 * the Divi Builder is active — on sites without Divi nothing is registered
 * and nothing is loaded.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Divi;

use RelevantForms\Forms\FormRenderer;
use RelevantForms\Integrations\Support\FormChoices;

/**
 * Hooks the Divi module into the builder and provides the data it needs.
 */
final class DiviIntegration {

	/**
	 * Register the Divi Builder hooks.
	 */
	public static function register(): void {
		add_action( 'et_builder_ready', [ self::class, 'loadModule' ] );
	}

	/**
	 * Load the module class. Runs on `et_builder_ready`, so the Divi Builder
	 * classes are guaranteed to be present.
	 */
	public static function loadModule(): void {
		if ( ! class_exists( '\ET_Builder_Module' ) ) {
			return;
		}

		require_once __DIR__ . '/FormModule.php';

		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueueBuilderAssets' ] );
	}

	/**
	 * Enqueue the Visual Builder preview component — only inside the Builder.
	 */
	public static function enqueueBuilderAssets(): void {
		if ( ! function_exists( 'et_core_is_fb_enabled' ) || ! et_core_is_fb_enabled() ) {
			return;
		}

		wp_enqueue_script(
			'relevant-forms-divi',
			RELEVANT_FORMS_URL . 'assets/js/divi-module.js',
			[ 'jquery' ],
			RELEVANT_FORMS_VERSION,
			true
		);

		wp_localize_script(
			'relevant-forms-divi',
			'relevantFormsDivi',
			[ 'forms' => self::formChoices() ]
		);
	}

	/**
	 * Choices for the module's form picker: every non-archived form, drafts
	 * labelled (only published forms are rendered on the front end).
	 *
	 * @return array<int|string, string> Form ID => label.
	 */
	public static function formChoices(): array {
		return FormChoices::all();
	}

	/**
	 * Render the chosen form exactly like the [relevant_form] shortcode does
	 * (published forms only; '' otherwise).
	 *
	 * @param int $formId Form ID.
	 */
	public static function renderForm( int $formId ): string {
		return FormRenderer::render( $formId );
	}
}
