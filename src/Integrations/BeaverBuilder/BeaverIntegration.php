<?php
/**
 * Beaver Builder integration: registers the "Relevant Form" module.
 *
 * The registration happens on `init` and is guarded — without Beaver Builder
 * nothing is loaded and nothing is registered.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\BeaverBuilder;

use RelevantForms\Forms\FormRenderer;
use RelevantForms\Integrations\Support\FormChoices;

/**
 * Hooks the module into Beaver Builder and provides the data it needs.
 */
final class BeaverIntegration {

	/**
	 * Register the Beaver Builder hook.
	 */
	public static function register(): void {
		add_action( 'init', [ self::class, 'load' ] );
	}

	/**
	 * Load the module registration and hand it to Beaver Builder.
	 */
	public static function load(): void {
		if ( ! class_exists( '\FLBuilder' ) || ! class_exists( '\FLBuilderModuleType' ) ) {
			return;
		}

		require_once __DIR__ . '/ModuleRegistration.php';
	}

	/**
	 * Module settings: the shared form choices with a leading placeholder.
	 *
	 * @return array<int|string, string>
	 */
	public static function formChoices(): array {
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
