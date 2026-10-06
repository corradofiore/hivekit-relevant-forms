<?php
/**
 * Divi 5 module integration tests: the Visual Builder preview endpoint, the
 * module library dependency-tree registration, the legacy-module skip inside
 * the Divi 5 builder, and the module metadata files.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Assets\FrontendAssets;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Integrations\Divi\DiviIntegration;
use RelevantForms\Integrations\Divi\ModernModule;
use RelevantForms\Tests\Integration\Support\AjaxRequests;
use RelevantForms\Tests\Integration\Support\DiviDependencyTreeStub;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class DiviModernTest extends TestCase {

	/**
	 * Without Divi 5 (ET_BUILDER_VERSION undefined — the tests below define
	 * it, so this one must run first) the dependency-tree hook is a safe
	 * no-op: nothing registers and no Divi class is required.
	 */
	public function testTheDependencyTreeHookDoesNothingWithoutTheDiviFiveFramework(): void {
		$tree = new DiviDependencyTreeStub();

		do_action( 'divi_module_library_modules_dependency_tree', $tree );

		$this->assertSame( [], $tree->dependencies );
	}

	/**
	 * Inside the Divi Builder the preview assets are enqueued even when the
	 * page content has no form yet: the module preview is fetched separately
	 * and injected after load, and must be styled and interactive (every
	 * front-end behaviour ships, whether the form needs it or not).
	 */
	public function testTheBuilderDocumentLoadsThePreviewAssets(): void {
		FrontendAssets::registerAssets();
		wp_dequeue_style( 'relevant-forms' );
		wp_dequeue_script( 'relevant-forms-total' );
		wp_dequeue_script( 'relevant-forms-pages' );

		unset( $_GET['et_fb'] );
		DiviIntegration::enqueueBuilderPreviewAssets();
		$this->assertFalse( wp_style_is( 'relevant-forms', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'relevant-forms-total', 'enqueued' ) );

		$_GET['et_fb'] = '1';
		DiviIntegration::enqueueBuilderPreviewAssets();
		$this->assertTrue( wp_style_is( 'relevant-forms', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'relevant-forms-total', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'relevant-forms-pages', 'enqueued' ) );
	}

	/**
	 * With Divi 5 active the module registers as a dependency of the module
	 * library tree; the tree then calls load() to hook the module up.
	 */
	public function testTheModuleRegistersWithTheDependencyTreeInDiviFive(): void {
		self::ensureDivi5Constant();

		require_once __DIR__ . '/Support/DiviDependencyInterface.php';

		$tree = new DiviDependencyTreeStub();

		do_action( 'divi_module_library_modules_dependency_tree', $tree );

		$this->assertCount( 1, $tree->dependencies );
		$this->assertInstanceOf( ModernModule::class, $tree->dependencies[0] );

		// Hooks fired with something that is not a dependency tree are
		// ignored without touching the module file.
		do_action( 'divi_module_library_modules_dependency_tree', 'not-a-tree' );

		$this->assertCount( 1, $tree->dependencies );

		$tree->dependencies[0]->load();

		$init = has_action( 'init', [ ModernModule::class, 'registerModule' ] );
		$vb   = has_action( 'divi_visual_builder_assets_before_enqueue_scripts', [ ModernModule::class, 'enqueueVisualBuilderAssets' ] );

		$this->assertNotFalse( $init );
		$this->assertNotFalse( $vb );

		// Keep the suite clean: the real callbacks need Divi's classes.
		remove_action( 'init', [ ModernModule::class, 'registerModule' ], $init );
		remove_action( 'divi_visual_builder_assets_before_enqueue_scripts', [ ModernModule::class, 'enqueueVisualBuilderAssets' ], $vb );
	}

	/**
	 * Inside the Divi 5 Visual Builder the legacy module stays unregistered
	 * (its placeholder would never resolve); on the front end it still
	 * registers so Divi 4 layouts keep rendering.
	 */
	public function testTheLegacyModuleIsSkippedInsideTheDiviFiveBuilder(): void {
		self::ensureDivi5Constant();

		unset( $_GET['et_fb'] );
		$this->assertFalse( DiviIntegration::shouldSkipLegacyModule() );

		$_GET['et_fb'] = '1';
		$this->assertTrue( DiviIntegration::shouldSkipLegacyModule() );
	}

	/**
	 * The Visual Builder preview endpoint returns the form's real front-end
	 * markup (same renderer as the shortcode).
	 */
	public function testPreviewEndpointReturnsTheFormsFrontEndMarkup(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = ( new FormRepository() )->create( 'Contact', FormSchema::empty( 'Contact' ), 'published' );

		$response = $this->dispatchPreview( $formId );

		$this->assertTrue( $response['success'] );
		$this->assertIsString( $response['data'] );
		$this->assertStringContainsString( '<form class="relevant-forms"', $response['data'] );
	}

	/**
	 * Draft, missing or unselected forms preview as an empty string — the
	 * component shows its own "not available" message for that.
	 */
	public function testPreviewEndpointReturnsNothingForUnavailableForms(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$draftId = ( new FormRepository() )->create( 'Sketch', FormSchema::empty( 'Sketch' ), 'draft' );

		$this->assertSame( '', $this->dispatchPreview( $draftId )['data'] );
		$this->assertSame( '', $this->dispatchPreview( 0 )['data'] );
		$this->assertSame( '', $this->dispatchPreview( 999999 )['data'] );
	}

	/**
	 * Users without a content-editing capability get a JSON error, and a
	 * missing nonce terminates the request without any form markup.
	 */
	public function testPreviewEndpointRequiresCapabilityAndNonce(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->dispatchPreview( 1 );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'forbidden', $response['data']['code'] );

		$_REQUEST['nonce'] = 'invalid';

		$this->assertSame( [], AjaxRequests::dispatch( [ DiviIntegration::class, 'preview' ] ) );
	}

	/**
	 * module.json and conversion-outline.json line up with the module class:
	 * the block name, the legacy shortcode and the attribute conversion that
	 * maps Divi 4 form_id values onto the Divi 5 formId attribute.
	 */
	public function testTheDiviModuleMetadataMatchesTheModuleContract(): void {
		require_once __DIR__ . '/Support/DiviDependencyInterface.php';

		$this->assertTrue( class_exists( ModernModule::class ) );

		$directory = RELEVANT_FORMS_PATH . 'src/Integrations/Divi/';
		$module    = wp_json_file_decode( $directory . 'module.json', [ 'associative' => true ] );
		$outline   = wp_json_file_decode( $directory . 'conversion-outline.json', [ 'associative' => true ] );

		$this->assertIsArray( $module );
		$this->assertSame( ModernModule::MODULE_NAME, $module['name'] );
		$this->assertSame( ModernModule::MODULE_NAME, $module['moduleIcon'] );
		$this->assertSame( ModernModule::D4_SHORTCODE, $module['d4Shortcode'] );
		$this->assertSame( 'module', $module['category'] );
		$this->assertSame( '0', $module['attributes']['formId']['default'] );

		$this->assertIsArray( $outline );
		$this->assertSame( 'formId.*', $outline['module']['form_id'] );
	}

	/**
	 * The Divi design controls style the form's input fields, not the module
	 * wrapper: the decoration groups Background, Border, Box Shadow, Filters
	 * and Font redirect their CSS to the fields through `styleProps`, and the
	 * Spacing group redirects padding specifically. Both files that feed the
	 * style pipeline — module.json on the server and the Visual Builder
	 * metadata registered from divi-module-modern.js — must declare the
	 * redirects, or the front end and the builder would disagree.
	 */
	public function testTheDesignControlsStyleTheFormFieldsThroughStyleProps(): void {
		$directory = RELEVANT_FORMS_PATH . 'src/Integrations/Divi/';
		$module    = wp_json_file_decode( $directory . 'module.json', [ 'associative' => true ] );
		$outline   = wp_json_file_decode( $directory . 'conversion-outline.json', [ 'associative' => true ] );

		$this->assertIsArray( $module );
		$this->assertIsArray( $outline );

		// The field selector both files must agree on.
		$fields = '.relevant-forms-field :is(input, select, textarea):not([type=checkbox]):not([type=radio])';

		$module_attr = $module['attributes']['module'];
		$style_props = $module_attr['styleProps'];
		$decoration  = $module_attr['settings']['decoration'];

		foreach ( [ 'background', 'border', 'boxShadow', 'filters', 'font' ] as $group ) {
			$this->assertArrayHasKey( $group, $style_props, "module.json styleProps must declare the {$group} redirect." );
			$this->assertStringContainsString(
				$fields,
				$style_props[ $group ]['selector'],
				"module.json styleProps.{$group} must target the form fields."
			);
			$this->assertArrayHasKey( $group, $decoration, "module.json decoration must expose the {$group} design group." );
		}

		$this->assertTrue( $style_props['boxShadow']['important'] );
		$this->assertStringContainsString(
			$fields,
			$style_props['spacing']['propertySelectors']['desktop']['value']['padding'],
			'module.json spacing must redirect padding to the form fields.'
		);
		$this->assertStringContainsString(
			$fields,
			$style_props['selectors']['desktop']['value'],
			'module.json must declare the expanded fields selector list.'
		);

		// The conversion outline maps the legacy advanced groups onto the
		// same Divi 5 decoration groups for converted Divi 4 content.
		$this->assertSame( 'module.decoration.background', $outline['advanced']['background'] );
		$this->assertSame( 'module.decoration.border', $outline['advanced']['border'] );
		$this->assertSame( 'module.decoration.boxShadow', $outline['advanced']['box_shadow']['default'] );
		$this->assertSame( 'module.decoration.filters', $outline['advanced']['filters']['default'] );
		$this->assertSame( 'module.decoration.font', $outline['advanced']['fonts']['module'] );

		// The Visual Builder registers its own metadata from the script; it
		// must redirect the same groups or the builder preview would style
		// the wrapper while the front end styles the fields.
		$script = file_get_contents( RELEVANT_FORMS_PATH . 'assets/js/divi-module-modern.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file, not a remote URL.

		$this->assertIsString( $script );
		$this->assertStringContainsString( $fields, $script );
		$this->assertStringContainsString( 'styleProps', $script );
		$this->assertStringContainsString( 'boxShadow', $script );
		$this->assertStringContainsString( 'propertySelectors', $script );
	}

	/**
	 * POST to the Visual Builder preview endpoint with a valid nonce.
	 *
	 * @param int $formId Form ID to preview.
	 * @return array<string, mixed> Decoded response body.
	 */
	private function dispatchPreview( int $formId ): array {
		$_POST['form_id']  = $formId;
		$_REQUEST['nonce'] = wp_create_nonce( 'relevant_forms_divi_builder' );

		return AjaxRequests::dispatch( [ DiviIntegration::class, 'preview' ] );
	}

	/**
	 * Define Divi 5's version constant once per process.
	 */
	private static function ensureDivi5Constant(): void {
		if ( ! defined( 'ET_BUILDER_VERSION' ) ) {
			define( 'ET_BUILDER_VERSION', '5.0.1' );
		}
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_POST, $_REQUEST['nonce'], $_GET['et_fb'] );

		parent::tearDown();
	}
}
