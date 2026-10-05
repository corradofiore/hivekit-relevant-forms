<?php
/**
 * Block editor integration: the server-rendered "Relevant Form" block,
 * outputting exactly what the [relevant_form] shortcode renders.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Gutenberg;

use RelevantForms\Assets\FrontendAssets;
use RelevantForms\Forms\FormRenderer;
use RelevantForms\Integrations\Support\FormChoices;

/**
 * Registers the block and its editor UI.
 */
final class GutenbergIntegration {

	/**
	 * Block name.
	 */
	public const BLOCK_NAME = 'relevant-forms/form-selector';

	/**
	 * Store the block comment marker used for usage discovery.
	 */
	public const BLOCK_MARKER = 'wp:relevant-forms/form-selector';

	/**
	 * Register the block on init (the block registry expects that).
	 */
	public static function register(): void {
		add_action( 'init', [ self::class, 'registerBlock' ] );
	}

	/**
	 * Register the editor script and the dynamic block.
	 */
	public static function registerBlock(): void {
		// The block declares the front-end stylesheet; make sure the handle
		// exists in admin context too (FrontendAssets only registers on the
		// front end).
		FrontendAssets::registerAssets();

		wp_register_script(
			'relevant-forms-block-editor',
			RELEVANT_FORMS_URL . 'assets/js/gutenberg-block.js',
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-server-side-render' ],
			RELEVANT_FORMS_VERSION,
			true
		);

		wp_set_script_translations( 'relevant-forms-block-editor', 'relevant-forms' );

		wp_localize_script(
			'relevant-forms-block-editor',
			'relevantFormsBlock',
			[
				'forms'       => FormChoices::all(),
				'selectLabel' => __( 'Select a form', 'relevant-forms' ),
			]
		);

		register_block_type(
			self::BLOCK_NAME,
			[
				'api_version'     => '3',
				'title'           => __( 'Relevant Form', 'relevant-forms' ),
				'description'     => __( 'Embed a form built with Relevant Forms.', 'relevant-forms' ),
				'category'        => 'widgets',
				'icon'            => 'feedback',
				'keywords'        => [ 'form', 'contact', 'relevant' ],
				'attributes'      => [
					'formId' => [
						'type'    => 'integer',
						'default' => 0,
					],
				],
				'editor_script'   => 'relevant-forms-block-editor',
				'style'           => 'relevant-forms',
				'render_callback' => [ self::class, 'renderBlock' ],
				'supports'        => [
					'html'      => false,
					'className' => false,
				],
			]
		);
	}

	/**
	 * Front-end output: identical to the [relevant_form] shortcode.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function renderBlock( array $attributes = [] ): string {
		return FormRenderer::render( absint( $attributes['formId'] ?? 0 ) );
	}
}
