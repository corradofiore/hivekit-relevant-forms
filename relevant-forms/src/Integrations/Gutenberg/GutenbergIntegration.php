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
					'html'       => false,
					'color'      => [
						'text'       => true,
						'background' => true,
						'gradients'  => false,
					],
					'typography' => [
						'fontSize' => true,
					],
					'spacing'    => [
						'padding' => true,
						'margin'  => true,
					],
					// The server-side border support reads the legacy key (core
					// dynamic blocks such as `core/archives` declare it the same way).
					'__experimentalBorder' => [
						'color'  => true,
						'radius' => true,
						'style'  => true,
						'width'  => true,
					],
				],
			]
		);
	}

	/**
	 * Front-end output: the form through the same code path as the
	 * shortcode, wrapped in the standard block wrapper so the editor's
	 * style panels (colors, typography, spacing, border — declared as
	 * block supports) reach the front end. Text color and font size are
	 * inherited by the form controls through the stylesheet bridge scoped
	 * to the block wrapper class.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function renderBlock( array $attributes = [] ): string {
		$html = FormRenderer::render( absint( $attributes['formId'] ?? 0 ) );

		if ( '' === $html ) {
			return '';
		}

		return sprintf(
			'<div %1$s>%2$s</div>',
			get_block_wrapper_attributes( [ 'class' => 'relevant-forms-embed' ] ),
			$html
		);
	}
}
