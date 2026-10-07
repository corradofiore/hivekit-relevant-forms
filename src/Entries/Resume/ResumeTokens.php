<?php
/**
 * Resume token generation, hashing and expiry helpers.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Entries\Resume;

/**
 * Save and Resume link tokens. Tokens are 256-bit random values; only the
 * SHA-256 hash is stored, so a leaked database row cannot be replayed as a
 * resume link.
 */
final class ResumeTokens {

	/**
	 * Generate a new token (64 hex characters, URL-safe).
	 */
	public static function generate(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Hash a token for storage and lookup.
	 *
	 * @param string $token Raw token.
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * UTC datetime a fresh token stops working.
	 *
	 * @param int $days Validity window in days (clamped to 1-365).
	 */
	public static function expiry( int $days ): string {
		$days = max( 1, min( 365, $days ) );

		return gmdate( 'Y-m-d H:i:s', time() + ( $days * 86400 ) );
	}

	/**
	 * Whether a stored expiry datetime is in the past (or missing).
	 *
	 * @param string $expiresAt UTC 'Y-m-d H:i:s' datetime.
	 */
	public static function expired( string $expiresAt ): bool {
		$expiresAt = trim( $expiresAt );

		return '' === $expiresAt || gmdate( 'Y-m-d H:i:s' ) > $expiresAt;
	}
}
