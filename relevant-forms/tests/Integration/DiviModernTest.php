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
