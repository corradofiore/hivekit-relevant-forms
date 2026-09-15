<?php
/**
 * Plugin Name:       Sentinel Forms
 * Description:       WordPress forms that understand which messages matter. Semantic spam filtering, priority alerts and secure private uploads.
 * Version:           0.1.0
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

// Composer autoloader (vendored dependencies).
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

define( 'SENTINEL_FORMS_VERSION', '0.1.0' );
define( 'SENTINEL_FORMS_FILE', __FILE__ );
define( 'SENTINEL_FORMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SENTINEL_FORMS_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ Plugin::class, 'boot' ] );
