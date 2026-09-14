<?php
/**
 * Exception used to intercept redirects in tests.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration\Support;

use Exception;

/**
 * Thrown by the sentinel_forms_redirect filter so tests can capture the URL.
 */
final class RedirectedException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string $url Redirect URL.
	 */
	public function __construct( public readonly string $url ) {
		parent::__construct( 'Redirected: ' . (string) $url );
	}
}
