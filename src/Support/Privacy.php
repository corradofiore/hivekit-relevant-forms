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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by filter_var() below, then hashed.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		$ip     = '' === $remote ? false : filter_var( $remote, FILTER_VALIDATE_IP );

		return self::hash( is_string( $ip ) ? $ip : 'unknown' );
	}

	/**
	 * Salted hash of the current user agent.
	 */
	public static function userAgentHash(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by sanitize_text_field() below, then hashed.
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		return self::hash( '' !== $agent ? $agent : 'unknown' );
	}

	/**
	 * HMAC a value with the WordPress auth salt so hashes are site-specific.
	 */
	private static function hash( string $value ): string {
		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}
}
