<?php
/**
 * Exception used to intercept non-redirect responses in tests.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration\Support;

use Exception;

/**
 * Carries the status and body of an intercepted response.
 */
final class StoppedException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body   Rendered response body.
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body,
	) {
		parent::__construct( 'Response intercepted: ' . (string) $status );
	}
}
