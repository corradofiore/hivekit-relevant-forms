<?php
/**
 * Base integration test case.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Integrations\Support\StyleCss;
use WP_UnitTestCase;

/**
 * Tests running against a real WordPress installation via wp-env.
 *
 * @group integration
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Set up the plugin tables for each test.
	 */
	public function setUp(): void {
		parent::setUp();

		\RelevantForms\Database\MigrationRunner::run();

		// Dynamic builder styles are attached to a shared handle; start each
		// test with it empty so assertions see only this test's rules.
		wp_dequeue_style( StyleCss::HANDLE );
		wp_deregister_style( StyleCss::HANDLE );
	}

	/**
	 * CSS attached to the dynamic builder stylesheet during this test.
	 */
	protected function inlineStyles(): string {
		return implode( "\n", (array) wp_styles()->get_data( StyleCss::HANDLE, 'after' ) );
	}
}
