<?php
/**
 * Privacy helpers: hashed representations of identifying request data.
 *
 * Raw IP addresses and user agents are never stored. Only keyed HMAC hashes
 * are persisted so that duplicate detection can work without keeping
 * personal data (doc §5, §10).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Support;

/**
 * Produces stable, salted hashes of client identifiers.
 */
final class Privacy {

	/**
	 * Salted hash of the current request IP.
	 */
	public static function ipHash(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed immediately, never stored or output raw.
		return self::hash( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
	}

	/**
	 * Salted hash of the current user agent.
	 */
	public static function userAgentHash(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed immediately, never stored or output raw.
		return self::hash( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? 'unknown' ) );
	}

	/**
	 * HMAC a value with the WordPress auth salt so hashes are site-specific.
	 */
	private static function hash( string $value ): string {
		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}
}
