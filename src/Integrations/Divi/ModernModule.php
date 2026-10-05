<?php
/**
 * Divi 5 module: the "Relevant Form" module registered with Divi's own
 * module library (module.json + ModuleRegistration), with the Visual Builder
 * preview driven by assets/js/divi-module-modern.js.
 *
 * Loaded by DiviIntegration::registerModernModule() — only when Divi 5+ is
 * active — so the ET\Builder classes only need to exist at that point.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Divi;

use RelevantForms\Forms\FormRenderer;

// Defence in depth: the autoloader may reach this file on sites without Divi 5.
if ( ! interface_exists( '\ET\Builder\Framework\DependencyManagement\Interfaces\DependencyInterface' ) ) {
	return;
}

/**
 * Divi 5 module registration, server-side rendering and Visual Builder
 * assets. Divi's module library dependency tree calls load() during boot.
 */
final class ModernModule implements \ET\Builder\Framework\DependencyManagement\Interfaces\DependencyInterface {

	/**
	 * Block/module name; also the icon name. Written in three places that
	 * must stay in sync: module.json (name + moduleIcon), this constant and
	 * assets/js/divi-module-modern.js.
	 */
	public const MODULE_NAME = 'relevant-forms/divi-form-selector';

	/**
	 * Legacy Divi 4 module slug; conversion-outline.json maps its attributes
	 * onto this module's when old layouts are converted.
	 */
	public const D4_SHORTCODE = 'relevant_form';

	/**
	 * DependencyInterface callback: Divi's module library dependency tree
	 * calls this when the builder framework boots.
	 */
	public function load(): void {
		add_action( 'init', [ self::class, 'registerModule' ] );
		add_action( 'divi_visual_builder_assets_before_enqueue_scripts', [ self::class, 'enqueueVisualBuilderAssets' ] );
	}

	/**
	 * Register the module as a Divi 5 block: module.json describes the
	 * module and its attributes, renderCallback() renders the front end.
	 */
	public static function registerModule(): void {
		\ET\Builder\Packages\ModuleLibrary\ModuleRegistration::register_module(
			__DIR__,
			[ 'render_callback' => [ self::class, 'renderCallback' ] ]
		);
	}

	/**
	 * Front-end (and Visual Builder) render callback: the chosen form's
	 * markup through the same renderer as the [relevant_form] shortcode,
	 * wrapped in the standard Divi module markup. Unpublished or deleted
	 * forms render nothing, mirroring the shortcode.
	 *
	 * @param array<string, mixed> $attrs    Module attributes.
	 * @param string               $content  Module inner content.
	 * @param object               $block    Block helper (parsed_block, block_type).
	 * @param object               $elements Elements helper.
	 * @return string Rendered module HTML ('' when there is nothing to show).
	 */
	public static function renderCallback( array $attrs, string $content, object $block, object $elements ): string {
		$parsed_attrs = (array) ( $block->parsed_block['attrs'] ?? [] );
		$form_id      = absint( $parsed_attrs['formId']['desktop']['value'] ?? 0 );
		$form_html    = FormRenderer::render( $form_id );

		if ( '' === $form_html ) {
			return '';
		}

		$module_inner = sprintf(
			'<div class="et_pb_module_inner">%s</div>',
			$form_html
		);

		return \ET\Builder\Packages\Module\Module::render(
			[
				'orderIndex'          => $block->parsed_block['orderIndex'] ?? 0,
				'storeInstance'       => $block->parsed_block['storeInstance'] ?? '',
				'attrs'               => $parsed_attrs,
				'elements'            => $elements,
				'id'                  => $block->parsed_block['id'] ?? '',
				'moduleClassName'     => 'relevant_forms_divi_form_selector',
				'name'                => $block->block_type->name,
				'classnamesFunction'  => [ self::class, 'moduleClassnames' ],
				'moduleCategory'      => $block->block_type->category,
				'stylesComponent'     => [ self::class, 'moduleStyles' ],
				'scriptDataComponent' => [ self::class, 'moduleScriptData' ],
				'children'            => $elements->style_components( [ 'attrName' => 'module' ] ) . $module_inner,
			]
		);
	}

	/**
	 * Module classnames callback (the default set is computed by Divi).
	 *
	 * @param array<string, mixed> $args Classname arguments.
	 * @return array<int, string> Additional CSS classes.
	 */
	public static function moduleClassnames( array $args ): array {
		return [];
	}

	/**
	 * Module styles callback: feeds the standard module style components
	 * (spacing, background, custom CSS fields) into Divi's style pipeline.
	 *
	 * @param array<string, mixed> $args Style arguments from the renderer.
	 */
	public static function moduleStyles( array $args ): void {
		$attrs                       = $args['attrs'] ?? [];
		$elements                    = $args['elements'];
		$settings                    = $args['settings'] ?? [];
		$default_printed_style_attrs = $args['defaultPrintedStyleAttrs'] ?? [];

		\ET\Builder\FrontEnd\Module\Style::add(
			[
				'id'            => $args['id'],
				'name'          => $args['name'],
				'orderIndex'    => $args['orderIndex'],
				'storeInstance' => $args['storeInstance'],
				'styles'        => [
					$elements->style(
						[
							'attrName'   => 'module',
							'styleProps' => [
								'defaultPrintedStyleAttrs' => $default_printed_style_attrs['module']['decoration'] ?? [],
								'disabledOn'               => [
									'disabledModuleVisibility' => $settings['disabledModuleVisibility'] ?? null,
								],
							],
						]
					),
					\ET\Builder\Packages\Module\Options\Css\CssStyle::style(
						[
							'selector'  => $args['orderClass'],
							'attr'      => $attrs['css'] ?? [],
							'cssFields' => self::customCssFields(),
						]
					),
				],
			]
		);
	}

	/**
	 * Module script data callback.
	 *
	 * @param array<string, mixed> $args Script data arguments.
	 * @return array<string, mixed> Script data payload.
	 */
	public static function moduleScriptData( array $args ): array {
		return [ 'data' => [] ];
	}

	/**
	 * Custom CSS fields defined in module.json, read back from the
	 * registered block type.
	 *
	 * @return array<string, mixed> Custom CSS fields.
	 */
	private static function customCssFields(): array {
		$registry   = \WP_Block_Type_Registry::get_instance();
		$block_type = $registry ? $registry->get_registered( self::MODULE_NAME ) : null;

		if ( ! $block_type || ! isset( $block_type->customCssFields ) ) {
			return [];
		}

		return (array) $block_type->customCssFields;
	}

	/**
	 * Register the Visual Builder assets: the app-window component that
	 * draws the module's settings panel and live preview, plus its
	 * localized data (forms, labels, preview endpoint).
	 */
	public static function enqueueVisualBuilderAssets(): void {
		if ( ! function_exists( 'et_core_is_fb_enabled' ) || ! et_core_is_fb_enabled() ) {
			return;
		}

		if ( ! function_exists( 'et_builder_d5_enabled' ) || ! et_builder_d5_enabled() ) {
			return;
		}

		\ET\Builder\VisualBuilder\Assets\PackageBuildManager::register_package_build(
			[
				'name'    => 'relevant-forms-divi',
				'version' => \RelevantForms\Support\AssetVersion::get( 'assets/js/divi-module-modern.js' ),
				'script'  => [
					'src'                => RELEVANT_FORMS_URL . 'assets/js/divi-module-modern.js',
					'deps'               => [ 'react', 'jquery', 'divi-module-library', 'wp-hooks', 'divi-rest' ],
					'enqueue_top_window' => false,
					'enqueue_app_window' => true,
					'data_app_window'    => self::localizedData(),
				],
			]
		);
	}

	/**
	 * Data handed to the Visual Builder component (exposed to the app
	 * window as the package's `data_app_window` global).
	 *
	 * @return array<string, mixed> Localized data.
	 */
	public static function localizedData(): array {
		$forms = [];

		foreach ( DiviIntegration::formChoices() as $form_id => $label ) {
			if ( '' === (string) $form_id ) {
				continue;
			}

			$forms[ (string) $form_id ] = [ 'label' => (string) $label ];
		}

		return [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'relevant_forms_divi_builder' ),
			'forms'         => $forms,
			'settingsGroup' => [ 'label' => __( 'Form Settings', 'relevant-forms' ) ],
			'formId'        => [
				'label'       => __( 'Form', 'relevant-forms' ),
				'placeholder' => __( 'Select a form', 'relevant-forms' ),
			],
			'noForms'       => __( 'No forms yet — create one in the Relevant Forms admin first.', 'relevant-forms' ),
			'openAdmin'     => __( 'Open Relevant Forms', 'relevant-forms' ),
			'noFormsUrl'    => admin_url( 'admin.php?page=relevant-forms' ),
			'selectForm'    => __( 'Select a form in the Form Settings panel to preview it here.', 'relevant-forms' ),
			'loading'       => __( 'Loading form preview…', 'relevant-forms' ),
			'loadFailed'    => __( 'The form preview could not be loaded.', 'relevant-forms' ),
			'formMissing'   => __( 'This form is not available: it may be unpublished or deleted.', 'relevant-forms' ),
		];
	}
}
