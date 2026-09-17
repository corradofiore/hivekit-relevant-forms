<?php
/**
 * Integration-only plugin loader.
 *
 * Loads the plugin without requiring its composer vendor autoloader. This
 * keeps Pest's PHPUnit 12 out of the container process, where the isolated
 * integration vendor provides PHPUnit 9.6 and wp-phpunit.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

$pluginRoot = dirname( __DIR__, 2 );

define( 'RELEVANT_FORMS_VERSION', '1.0.0' );
define( 'RELEVANT_FORMS_FILE', $pluginRoot . '/relevant-forms.php' );
define( 'RELEVANT_FORMS_PATH', $pluginRoot . '/' );
define( 'RELEVANT_FORMS_URL', 'https://example.org/wp-content/plugins/relevant-forms/' );

require_once $pluginRoot . '/src/Support/functions.php';

spl_autoload_register(
	static function ( string $className ) use ( $pluginRoot ): void {
		$prefix = 'RelevantForms\\';

		if ( ! str_starts_with( $className, $prefix ) ) {
			return;
		}

		$file = $pluginRoot . '/src/' . str_replace( '\\', '/', substr( $className, strlen( $prefix ) ) ) . '.php';

		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

add_action( 'plugins_loaded', [ \RelevantForms\Bootstrap\Plugin::class, 'boot' ] );
