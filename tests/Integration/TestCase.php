<?php
/**
 * Base integration test case.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

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
	}
}
