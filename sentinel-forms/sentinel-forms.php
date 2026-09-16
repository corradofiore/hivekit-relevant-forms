<?php
/**
 * Plugin Name:       Sentinel Forms
 * Description:       WordPress forms with entries, local anti-spam and optional Sentinel Cloud intelligence (semantic filtering, priority alerts, managed secure uploads).
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sentinel-forms
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use SentinelForms\Bootstrap\Plugin;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Composer autoloader (source checkouts); release builds ship a plain autoloader.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'SentinelForms\\';

			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}

			$relative = substr( $class_name, strlen( $prefix ) );
			$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require $path;
			}
		}
	);

	require_once __DIR__ . '/src/Support/functions.php';
}

define( 'SENTINEL_FORMS_VERSION', '1.0.0' );
define( 'SENTINEL_FORMS_FILE', __FILE__ );
define( 'SENTINEL_FORMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SENTINEL_FORMS_URL', plugin_dir_url( __FILE__ ) );

// Public template functions (loaded here, not via the autoloader, so the
// ABSPATH guard in the file cannot run in CLI tooling contexts).
require_once __DIR__ . '/src/Support/functions.php';

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ Plugin::class, 'boot' ] );
