<?php
/**
 * Integration-only plugin loader.
 *
 * Loads the plugin without requiring its composer vendor autoloader. This
 * keeps Pest's PHPUnit 12 out of the container process, where the isolated
 * integration vendor provides PHPUnit 9.6 and wp-phpunit.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

$pluginRoot = dirname( __DIR__, 2 );

define( 'SENTINEL_FORMS_VERSION', '1.0.0' );
define( 'SENTINEL_FORMS_FILE', $pluginRoot . '/sentinel-forms.php' );
define( 'SENTINEL_FORMS_PATH', $pluginRoot . '/' );
define( 'SENTINEL_FORMS_URL', 'https://example.org/wp-content/plugins/sentinel-forms/' );

require_once $pluginRoot . '/src/Support/functions.php';

spl_autoload_register(
	static function ( string $className ) use ( $pluginRoot ): void {
		$prefix = 'SentinelForms\\';

		if ( ! str_starts_with( $className, $prefix ) ) {
			return;
		}

		$file = $pluginRoot . '/src/' . str_replace( '\\', '/', substr( $className, strlen( $prefix ) ) ) . '.php';

		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

add_action( 'plugins_loaded', [ \SentinelForms\Bootstrap\Plugin::class, 'boot' ] );
