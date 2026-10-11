<?php
/**
 * Unit test case with Brain Monkey support.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use RelevantForms\Tests\Unit\Support\WpdbDouble;

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

		/*
		 * Collaborators that talk to the database type-hint \wpdb or read it
		 * from the global; neither exists in a unit run, so the shared double
		 * answers to the real name. Tests that exercise a write install their
		 * own instance in $GLOBALS['wpdb'].
		 */
		if ( ! class_exists( 'wpdb' ) ) {
			class_alias( WpdbDouble::class, 'wpdb' );
		}

		Monkey\Functions\when( '__' )->returnArg();
		Monkey\Functions\when( 'esc_html__' )->returnArg();
		Monkey\Functions\when( 'esc_attr__' )->returnArg();
		Monkey\Functions\when( '_n' )->alias(
			static fn ( string $single, string $plural, int $number ): string => 1 === $number ? $single : $plural
		);
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		/*
		 * Email field validation delegates to the domain checker, which reads
		 * its mode from the settings. Unit tests run with the check off so they
		 * stay pure — EmailDeliverabilityTest enables it through the DNS and
		 * SMTP seams. A test that needs other stored options overrides this.
		 */
		Monkey\Functions\when( 'get_option' )->alias(
			static function ( string $name, mixed $fallback = false ): mixed {
				return 'relevant_forms_settings' === $name ? [ 'email_check_mode' => 'off' ] : $fallback;
			}
		);
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
