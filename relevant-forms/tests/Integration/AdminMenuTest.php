<?php
/**
 * Admin menu integration tests: the form editor page is hidden from the
 * menu, reachable from the Forms screen, and the legacy slug redirects.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\AdminMenu;
use RelevantForms\Admin\Pages\FormListPage;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Tests\Integration\Support\CapturesRedirects;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class AdminMenuTest extends TestCase {

	use CapturesRedirects;

	/**
	 * Menu globals backup.
	 *
	 * @var array<string, mixed>
	 */
	private array $backup = [];

	/**
	 * Snapshot the menu globals before the test mutates them.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->backup = [
			'menu'              => $GLOBALS['menu'] ?? null,
			'submenu'           => $GLOBALS['submenu'] ?? null,
			'_registered_pages' => $GLOBALS['_registered_pages'] ?? null,
			'title'             => $GLOBALS['title'] ?? null,
		];

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	/**
	 * The hidden editor page gets a browser title naming the form.
	 */
	public function testTheEditorPageTitleNamesTheForm(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$formId     = $repository->create( 'Contact', FormSchema::empty( 'Contact' ), 'draft' );

		$_GET['form_id'] = (string) $formId;
		AdminMenu::setEditorPageTitle();

		$this->assertSame( 'Edit Form: Contact', $GLOBALS['title'] );

		unset( $_GET['form_id'] );
		AdminMenu::setEditorPageTitle();

		$this->assertSame( 'Add New Form', $GLOBALS['title'] );
	}

	/**
	 * The editor page is registered but not shown as a menu item anymore.
	 */
	public function testTheEditorPageIsHiddenFromTheMenu(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		AdminMenu::register();
		AdminMenu::build();

		$this->assertNotFalse(
			has_action( 'admin_menu', [ AdminMenu::class, 'redirectLegacyEditorSlug' ] ),
			'The legacy slug must redirect before the menu access check denies the request.'
		);

		/*
		 * The entry is registered while the request is routed: core resolves
		 * the page and its parent from the submenu before rendering.
		 */
		$this->assertContains( 'relevant-form-edit', $this->submenuSlugs() );

		$this->assertArrayHasKey(
			get_plugin_page_hookname( 'relevant-form-edit', 'relevant-forms' ),
			$GLOBALS['_registered_pages'] ?? []
		);

		/*
		 * It is dropped right before the menu is rendered, so no menu item
		 * is ever printed.
		 */
		AdminMenu::highlightEditorParent( '' );

		$slugs = $this->submenuSlugs();

		$this->assertContains( 'relevant-forms', $slugs );
		$this->assertNotContains( 'relevant-form-edit', $slugs );
		$this->assertNotContains( 'relevant-form-new', $slugs );

		// Still reachable by URL: the page hook stays registered.
		$hooks = array_filter(
			array_keys( $GLOBALS['_registered_pages'] ?? [] ),
			static fn ( string $hook ): bool => str_contains( $hook, 'relevant-form-edit' )
		);

		$this->assertNotEmpty( $hooks );
	}

	/**
	 * The Logs viewer is registered after Diagnostics.
	 */
	public function testTheLogsPageIsRegisteredInTheMenu(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		AdminMenu::register();
		AdminMenu::build();

		$slugs = $this->submenuSlugs();

		$this->assertContains( 'relevant-logs', $slugs );

		$diagnostics = array_search( 'relevant-diagnostics', $slugs, true );
		$logs        = array_search( 'relevant-logs', $slugs, true );

		$this->assertNotFalse( $logs );
		$this->assertGreaterThan( (int) $diagnostics, (int) $logs );
	}

	/**
	 * Slugs of the Relevant Forms submenu items.
	 *
	 * @return list<string>
	 */
	private function submenuSlugs(): array {
		return array_map(
			static fn ( array $item ): string => (string) $item[2],
			$GLOBALS['submenu']['relevant-forms'] ?? []
		);
	}

	/**
	 * The Forms screen is the entry point: it links to the new slug.
	 */
	public function testTheFormsScreenLinksToTheEditor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new FormRepository();
		$repository->create( 'Contact', FormSchema::empty( 'Contact' ), 'published' );

		ob_start();
		FormListPage::render();
		$html = html_entity_decode( (string) ob_get_clean(), ENT_QUOTES );

		$this->assertStringContainsString( 'page=relevant-form-edit', $html );
		$this->assertStringContainsString( 'page=relevant-form-edit&form_id=', $html );
		$this->assertStringNotContainsString( 'relevant-form-new', $html );
	}

	/**
	 * The menu stays highlighted while the hidden editor page is open.
	 */
	public function testTheMenuStaysHighlightedOnTheEditorPage(): void {
		$_GET['page'] = 'relevant-form-edit';

		$this->assertSame( 'relevant-forms', AdminMenu::highlightEditorParent( 'index.php' ) );
		$this->assertSame( 'relevant-forms', AdminMenu::highlightEditorSubmenu( 'index.php' ) );

		$_GET['page'] = 'relevant-settings';

		$this->assertSame( 'index.php', AdminMenu::highlightEditorParent( 'index.php' ) );
		$this->assertSame( 'index.php', AdminMenu::highlightEditorSubmenu( 'index.php' ) );

		// A null stays null: casting it to '' would count as "set" and
		// suppress core's own highlighting for ordinary submenu pages.
		$this->assertNull( AdminMenu::highlightEditorSubmenu( null ) );
	}

	/**
	 * The previous slug redirects to the new one, keeping the form id.
	 */
	public function testTheLegacySlugRedirectsToTheNewPage(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_GET['page']    = 'relevant-form-new';
		$_GET['form_id'] = '7';

		$url = $this->captureRedirect( [ AdminMenu::class, 'redirectLegacyEditorSlug' ] );

		$this->assertStringContainsString( 'page=relevant-form-edit', $url );
		$this->assertStringContainsString( 'form_id=7', $url );
	}

	/**
	 * Restore the menu globals.
	 */
	public function tearDown(): void {
		unset( $_GET['page'], $_GET['form_id'] );

		$this->restoreGlobal( 'menu', $this->backup['menu'] );
		$this->restoreGlobal( 'submenu', $this->backup['submenu'] );
		$this->restoreGlobal( '_registered_pages', $this->backup['_registered_pages'] );
		$this->restoreGlobal( 'title', $this->backup['title'] );

		parent::tearDown();
	}

	/**
	 * Restore one of the globals captured in setUp().
	 *
	 * @param string $name  Global name.
	 * @param mixed  $value Backed-up value.
	 */
	private function restoreGlobal( string $name, mixed $value ): void {
		if ( null === $value ) {
			unset( $GLOBALS[ $name ] );

			return;
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring test state mutated by AdminMenu::build().
		$GLOBALS[ $name ] = $value;
	}
}
