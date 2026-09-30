<?php
/**
 * Logs admin page integration tests: rendering, filters, pagination and the
 * clear action.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\LogsController;
use RelevantForms\Admin\Pages\LogsPage;
use RelevantForms\Tests\Integration\Support\CapturesRedirects;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class LogsPageTest extends TestCase {

	use CapturesRedirects;

	/**
	 * Administrator context.
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Clean request state.
	 */
	public function tearDown(): void {
		unset( $_GET['paged'], $_GET['level'], $_GET['s'], $_GET['cleared'], $_REQUEST['_wpnonce'] );

		parent::tearDown();
	}

	/**
	 * Empty table: empty state plus the debug-logging hint when it is off.
	 */
	public function testThePageRendersTheEmptyStateAndTheDebugHint(): void {
		update_option( 'relevant_forms_settings', [] );

		$html = $this->renderPage();

		$this->assertStringContainsString( 'No log entries found.', $html );
		$this->assertStringContainsString( 'Enable debug logging in', $html );
	}

	/**
	 * With debug logging on, the hint is gone.
	 */
	public function testTheDebugHintIsHiddenWhenDebugLoggingIsOn(): void {
		update_option( 'relevant_forms_settings', [ 'debug_logging' => 1 ] );

		$html = $this->renderPage();

		$this->assertStringNotContainsString( 'Enable debug logging in', $html );
	}

	/**
	 * Rows render with a level badge and an expandable context.
	 */
	public function testRowsRenderWithLevelBadgeAndContext(): void {
		$this->insertLog( 'error', 'Cloud request failed', [ 'form_id' => 7 ] );
		$this->insertLog( 'info', 'Form saved' );

		$html = $this->renderPage();

		$this->assertStringContainsString( 'relevant-forms-status--error', $html );
		$this->assertStringContainsString( 'Cloud request failed', $html );
		$this->assertStringContainsString( '<details>', $html );
		$this->assertStringContainsString( 'form_id', $html );
		$this->assertStringContainsString( 'Form saved', $html );
	}

	/**
	 * The level filter narrows the table.
	 */
	public function testTheLevelFilterShowsOnlyMatchingRows(): void {
		$this->insertLog( 'error', 'Boom went wrong' );
		$this->insertLog( 'warning', 'Watch out' );

		$_GET['level'] = 'error';

		$html = $this->renderPage();

		$this->assertStringContainsString( 'Boom went wrong', $html );
		$this->assertStringNotContainsString( 'Watch out', $html );
	}

	/**
	 * The search box matches message text.
	 */
	public function testTheSearchFiltersMessages(): void {
		$this->insertLog( 'error', 'Alpha failure' );
		$this->insertLog( 'error', 'Beta trouble' );

		$_GET['s'] = 'failure';

		$html = $this->renderPage();

		$this->assertStringContainsString( 'Alpha failure', $html );
		$this->assertStringNotContainsString( 'Beta trouble', $html );
	}

	/**
	 * Entries paginate twenty per page, newest first.
	 */
	public function testEntriesPaginate(): void {
		for ( $i = 1; $i <= 25; $i++ ) {
			$this->insertLog( 'debug', sprintf( 'Entry %02d', $i ) );
		}

		$firstPage = $this->renderPage();

		$this->assertStringContainsString( 'Entry 25', $firstPage );
		$this->assertStringContainsString( 'Entry 06', $firstPage );
		$this->assertStringNotContainsString( 'Entry 05', $firstPage );
		$this->assertStringContainsString( 'Showing 1–20 of 25', $firstPage );

		$_GET['paged'] = '2';

		$secondPage = $this->renderPage();

		$this->assertStringContainsString( 'Entry 05', $secondPage );
		$this->assertStringContainsString( 'Entry 01', $secondPage );
		$this->assertStringNotContainsString( 'Entry 06', $secondPage );
		$this->assertStringContainsString( 'Showing 21–25 of 25', $secondPage );
	}

	/**
	 * The clear action empties the table and redirects with a marker.
	 */
	public function testTheClearActionDeletesEveryEntry(): void {
		$this->insertLog( 'error', 'First' );
		$this->insertLog( 'error', 'Second' );

		$url = $this->captureRedirect( [ LogsController::class, 'handle' ], 'relevant_forms_clear_logs' );

		$this->assertStringContainsString( 'page=relevant-logs', $url );
		$this->assertStringContainsString( 'cleared=1', $url );

		global $wpdb;

		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'relevant_logs' ) );
	}

	/**
	 * A bogus nonce is refused.
	 */
	public function testTheClearActionRejectsABogusNonce(): void {
		$_REQUEST['_wpnonce'] = 'bogus';

		$this->expectException( \WPDieException::class );

		LogsController::handle();
	}

	/**
	 * A subscriber cannot open the page.
	 */
	public function testThePageRequiresTheManageCapability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->expectException( \WPDieException::class );

		LogsPage::render();
	}

	/**
	 * Insert one log row directly.
	 *
	 * @param string               $level   Log level.
	 * @param string               $message Message text.
	 * @param array<string, mixed> $context Optional context.
	 */
	private function insertLog( string $level, string $message, array $context = [] ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'relevant_logs',
			[
				'level'      => $level,
				'message'    => $message,
				'context'    => [] !== $context ? (string) wp_json_encode( $context ) : null,
				'created_at' => '2026-09-30 10:00:00',
			]
		);
	}

	/**
	 * Render the page into a string.
	 */
	private function renderPage(): string {
		ob_start();
		LogsPage::render();

		return (string) ob_get_clean();
	}
}
