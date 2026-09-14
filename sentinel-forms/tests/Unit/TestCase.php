<?php
/**
 * Unit test case with Brain Monkey support.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Base class for pure unit tests (no WordPress bootstrap).
 */
abstract class TestCase extends PhpUnitTestCase {

	/**
	 * Set up Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Monkey\Functions\when( '__' )->returnArg();
		Monkey\Functions\when( 'esc_html__' )->returnArg();
		Monkey\Functions\when( 'esc_attr__' )->returnArg();
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
