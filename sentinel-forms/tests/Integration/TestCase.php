<?php
/**
 * Base integration test case.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

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

		\SentinelForms\Database\MigrationRunner::run();
	}
}
