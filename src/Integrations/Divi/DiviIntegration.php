<?php
/**
 * Divi Builder integration. Divi 4 (and lower) get the classic
 * ET_Builder_Module registered from `et_builder_ready`; Divi 5 renders
 * third-party modules through its own module library, so there the module
 * is registered as a Divi 5 module (module.json + ModuleRegistration)
 * through the module library dependency tree instead.
 *
 * The whole integration hangs off Divi hooks, which only fire while the
 * Divi Builder is active — on sites without Divi nothing is registered and
 * nothing is loaded.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Divi;

use RelevantForms\Forms\FormRenderer;
use RelevantForms\Integrations\Support\ButtonStyleCss;
use RelevantForms\Integrations\Support\FormChoices;
use RelevantForms\Integrations\Support\TextStyleCss;

/**
 * Hooks the Divi modules into the builder and provides the data they need.
 */
final class DiviIntegration {

	/**
	 * Whether the legacy (Divi 4) module was registered in this request.
	 *
	 * @var bool
	 */
	private static bool $legacy_module_loaded = false;

	/**
	 * Register the Divi Builder hooks.
	 */
	public static function register(): void {
		add_action( 'et_builder_ready', [ self::class, 'loadModule' ] );
		add_action( 'divi_module_library_modules_dependency_tree', [ self::class, 'registerModernModule' ] );

		// The Visual Builder renders module previews after the builder
		// document has loaded (admin-ajax -> HTML injected into the page),
		// so a render-time enqueue can never reach them: the preview assets
		// have to be enqueued for the builder document itself (the same
		// pattern WPForms uses in its Divi integration).
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueueBuilderPreviewAssets' ], 20 );

		if ( wp_doing_ajax() ) {
			add_action( 'wp_ajax_relevant_forms_divi_preview', [ self::class, 'preview' ] );
		}
	}

	/**
	 * Enqueue the front-end assets inside the Divi Builder so module previews
	 * are styled AND interactive. Runs after FrontendAssets has registered
	 * the handles. Which behaviours a form needs is only known server-side
	 * at render time, so the builder document loads them all — every boot is
	 * idempotent and re-runs on the injected preview markup.
	 */
	public static function enqueueBuilderPreviewAssets(): void {
		if ( ! self::isBuilderRequest() ) {
			return;
		}

		wp_enqueue_style( 'relevant-forms' );

		foreach (
			[
				'relevant-forms-upload',
				'relevant-forms-counter',
				'relevant-forms-conditional',
				'relevant-forms-total',
				'relevant-forms-pages',
				'relevant-forms-preview',
			] as $handle
		) {
			wp_enqueue_script( $handle );
		}
	}

	/**
	 * Load the legacy module class. Runs on `et_builder_ready`, so the Divi
	 * Builder classes are guaranteed to be present.
	 */
	public static function loadModule(): void {
		if ( ! class_exists( '\ET_Builder_Module' ) ) {
			return;
		}

		if ( self::shouldSkipLegacyModule() ) {
			// The Divi 5 Visual Builder renders modules through the Divi 5
			// module library; the legacy module is only needed on the front
			// end there, to render layouts built with Divi 4.
			return;
		}

		require_once __DIR__ . '/FormModule.php';

		self::$legacy_module_loaded = true;

		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueueBuilderAssets' ] );
	}

	/**
	 * Skip the legacy module inside the Divi 5 Visual Builder: a legacy
	 * registration has no module library component there, which leaves its
	 * placeholder stuck on the loading state (the same pattern WPForms and
	 * other integrations follow).
	 */
	public static function shouldSkipLegacyModule(): bool {
		return self::isDivi5OrHigher() && self::isBuilderRequest();
	}

	/**
	 * Whether the active Divi Builder is version 5 or higher.
	 */
	private static function isDivi5OrHigher(): bool {
		if ( ! defined( 'ET_BUILDER_VERSION' ) ) {
			return false;
		}

		$version = constant( 'ET_BUILDER_VERSION' );

		return is_string( $version ) && version_compare( $version, '5.0.0', '>=' );
	}

	/**
	 * Whether the current request is rendered inside the Visual Builder.
	 */
	private static function isBuilderRequest(): bool {
		return ! empty( $_GET['et_fb'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Register the Divi 5 module with the module library dependency tree.
	 *
	 * @param mixed $dependency_tree Divi's dependency tree (object with add_dependency()).
	 */
	public static function registerModernModule( $dependency_tree ): void {
		if ( ! self::isDivi5OrHigher() ) {
			return;
		}

		if ( ! is_object( $dependency_tree ) || ! method_exists( $dependency_tree, 'add_dependency' ) ) {
			return;
		}

		require_once __DIR__ . '/ModernModule.php';

		if ( ! class_exists( '\RelevantForms\Integrations\Divi\ModernModule' ) ) {
			return;
		}

		$dependency_tree->add_dependency( new ModernModule() );
	}

	/**
	 * AJAX preview for the Divi 5 Visual Builder component: the selected
	 * form's front-end markup. Nonce- and capability-protected; the preview
	 * only ever shows published forms, i.e. what the public site shows.
	 */
	public static function preview(): void {
		check_ajax_referer( 'relevant_forms_divi_builder', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'code' => 'forbidden' ], 403 );
		}

		$form_id = absint( $_POST['form_id'] ?? 0 );
		$html    = self::renderForm( $form_id );

		if ( '' !== $html ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; every value is validated by ButtonStyleCss before output.
			$raw_buttons = isset( $_POST['buttons'] ) && is_string( $_POST['buttons'] ) ? wp_unslash( $_POST['buttons'] ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; every value is validated by TextStyleCss before output.
			$raw_texts = isset( $_POST['texts'] ) && is_string( $_POST['texts'] ) ? wp_unslash( $_POST['texts'] ) : '';

			$html = ButtonStyleCss::scopedHtml( $html, self::previewStyleValues( $raw_buttons, [ 'background', 'text', 'radius', 'padding' ] ), 'rf-divi-' );
			$html = TextStyleCss::scopedHtml( $html, self::previewStyleValues( $raw_texts, [ 'color', 'size', 'weight' ] ), 'rf-divi-' );
		}

		wp_send_json_success( $html );
	}

	/**
	 * The style values posted by the Visual Builder preview component as
	 * JSON. Unknown shapes resolve to empty strings, so a tampered payload
	 * simply previews an unstyled module.
	 *
	 * @param string       $raw  JSON payload ('' when the component sent none).
	 * @param list<string> $keys Value keys to read.
	 * @return array<string, string> Style values.
	 */
	private static function previewStyleValues( string $raw, array $keys ): array {
		$data = '' === $raw ? null : json_decode( $raw, true );

		$values = [];

		foreach ( $keys as $key ) {
			$value          = is_array( $data ) ? ( $data[ $key ] ?? '' ) : '';
			$values[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $values;
	}

	/**
	 * Enqueue the Visual Builder preview component — only inside the Builder.
	 */
	public static function enqueueBuilderAssets(): void {
		if ( ! self::$legacy_module_loaded ) {
			return;
		}

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
