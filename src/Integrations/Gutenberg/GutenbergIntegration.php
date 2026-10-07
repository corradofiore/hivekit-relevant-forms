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
use RelevantForms\Integrations\Support\ButtonStyleCss;
use RelevantForms\Integrations\Support\FormChoices;
use RelevantForms\Integrations\Support\TextStyleCss;

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
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render' ],
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
					'formId'          => [
						'type'    => 'integer',
						'default' => 0,
					],
					'buttonBgColor'   => [
						'type'    => 'string',
						'default' => '',
					],
					'buttonTextColor' => [
						'type'    => 'string',
						'default' => '',
					],
					'buttonRadius'    => [
						'type'    => 'string',
						'default' => '',
					],
					'buttonPadding'   => [
						'type'    => 'object',
						'default' => [],
					],

					/*
					 * The text outside the controls (labels, option labels, help text,
					 * display headings). Prefixed deliberately: core's color support
					 * already owns `textColor`.
					 */
					'textStyleColor'  => [
						'type'    => 'string',
						'default' => '',
					],
					'textStyleSize'   => [
						'type'    => 'string',
						'default' => '',
					],
					'textStyleWeight' => [
						'type'    => 'string',
						'default' => '',
					],
				],
				'editor_script'   => 'relevant-forms-block-editor',
				'style'           => 'relevant-forms',
				'render_callback' => [ self::class, 'renderBlock' ],
				'supports'        => [
					'html'                 => false,
					'color'                => [
						'text'       => true,
						'background' => true,
						'gradients'  => false,
					],
					'typography'           => [
						'fontSize' => true,
					],
					'spacing'              => [
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
	 * to the block wrapper class. The block's own "Button styles" controls
	 * override the theme's button styling for this embed only.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function renderBlock( array $attributes = [] ): string {
		$html = FormRenderer::render( absint( $attributes['formId'] ?? 0 ) );

		if ( '' === $html ) {
			return '';
		}

		$html = ButtonStyleCss::scopedHtml( $html, self::buttonValues( $attributes ), 'rf-gb-' );
		$html = TextStyleCss::scopedHtml( $html, self::textStyleValues( $attributes ), 'rf-gb-' );

		return sprintf(
			'<div %1$s>%2$s</div>',
			get_block_wrapper_attributes( [ 'class' => 'relevant-forms-embed' ] ),
			$html
		);
	}

	/**
	 * The text style control values from the block attributes, normalized for
	 * TextStyleCss.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, string> Style values.
	 */
	private static function textStyleValues( array $attributes ): array {
		$values = [];

		foreach (
			[
				'color'  => 'textStyleColor',
				'size'   => 'textStyleSize',
				'weight' => 'textStyleWeight',
			] as $key => $attribute
		) {
			$values[ $key ] = is_string( $attributes[ $attribute ] ?? null ) ? $attributes[ $attribute ] : '';
		}

		return $values;
	}

	/**
	 * The button control values from the block attributes, normalized for
	 * ButtonStyleCss (padding arrives as a BoxControl object of side values).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, string> Style values.
	 */
	private static function buttonValues( array $attributes ): array {
		$padding = is_array( $attributes['buttonPadding'] ?? null ) ? $attributes['buttonPadding'] : [];

		$sides       = [];
		$has_padding = false;

		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			$value = is_string( $padding[ $side ] ?? null ) ? trim( $padding[ $side ] ) : '';

			if ( '' !== $value ) {
				$has_padding = true;
				$sides[]     = $value;
				continue;
			}

			$sides[] = '0';
		}

		return [
			'background' => is_string( $attributes['buttonBgColor'] ?? null ) ? $attributes['buttonBgColor'] : '',
			'text'       => is_string( $attributes['buttonTextColor'] ?? null ) ? $attributes['buttonTextColor'] : '',
			'radius'     => is_string( $attributes['buttonRadius'] ?? null ) ? $attributes['buttonRadius'] : '',
			'padding'    => $has_padding ? implode( ' ', $sides ) : '',
		];
	}
}
