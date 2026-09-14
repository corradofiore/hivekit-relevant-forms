<?php
/**
 * Integration test bootstrap: loads the WordPress test library and the plugin.
 *
 * Runs inside the wp-env "tests-wordpress" container where the WordPress
 * develop test suite lives in /tmp/wordpress-tests-lib.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI diagnostic, not web output.
	fwrite( STDERR, 'WordPress test library not found. Run tests via `composer test:integration` (wp-env).' . PHP_EOL );
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require_once dirname( __DIR__, 2 ) . '/sentinel-forms.php';
	}
);

require_once $_tests_dir . '/includes/bootstrap.php';
