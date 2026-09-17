<?php
/**
 * PHPUnit bootstrap: minimal WordPress constants for pure unit tests,
 * then the Composer autoloader.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

require_once dirname( __DIR__ ) . '/src/Support/functions.php';
