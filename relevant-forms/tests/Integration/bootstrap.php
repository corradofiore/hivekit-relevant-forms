<?php
/**
 * Integration test bootstrap.
 *
 * Runs inside the wp-env "tests-wordpress" container. Loads the isolated
 * integration vendor (PHPUnit 9.6 + wp-phpunit test framework) before the
 * plugin's own autoloader so the correct PHPUnit classes win.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

// 1. Register the integration project's autoloader FIRST (PHPUnit 9.6).
$integrationVendor = __DIR__ . '/vendor/autoload.php';

if ( ! file_exists( $integrationVendor ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI diagnostic, not web output.
	fwrite( STDERR, 'Integration dependencies missing. Run `composer install` in tests/integration (inside the container).' . PHP_EOL );
	exit( 1 );
}

require_once $integrationVendor;

// 2. Locate the WordPress test framework shipped by wp-phpunit.
$wpPhpUnitDir = __DIR__ . '/vendor/wp-phpunit/wp-phpunit';

if ( ! file_exists( $wpPhpUnitDir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI diagnostic, not web output.
	fwrite( STDERR, 'wp-phpunit test framework not found in tests/Integration/vendor.' . PHP_EOL );
	exit( 1 );
}

// 3. Use the database configuration provided by the wp-env container.
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	$configPath = getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) !== false ? getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) : '/wordpress-phpunit/wp-tests-config.php';
	define( 'WP_TESTS_CONFIG_FILE_PATH', $configPath );
}

require_once $wpPhpUnitDir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require_once __DIR__ . '/load-plugin.php';
	}
);

require_once $wpPhpUnitDir . '/includes/bootstrap.php';
