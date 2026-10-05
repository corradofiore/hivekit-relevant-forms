<?php
/**
 * Bricks integration: registers the "Relevant Form" element.
 *
 * Bricks only loads custom elements registered on `init` (priority 11, per
 * the Bricks developer docs); without Bricks the hook is a no-op.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Bricks;

use RelevantForms\Forms\FormRenderer;
use RelevantForms\Integrations\Support\FormChoices;

/**
 * Hooks the element into Bricks and provides the data it needs.
 */
final class BricksIntegration {

	/**
	 * Register the Bricks hook.
	 */
	public static function register(): void {
		add_action( 'init', [ self::class, 'load' ], 11 );
	}

	/**
	 * Load the element class and register it with Bricks.
	 */
	public static function load(): void {
		if ( ! class_exists( '\Bricks\Elements' ) || ! class_exists( '\Bricks\Element' ) ) {
			return;
		}

		require_once __DIR__ . '/FormElement.php';
	}

	/**
	 * Element control options: the shared form choices with a leading
	 * placeholder.
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
