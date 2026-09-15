<?php
/**
 * Download authorization DTO.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Contracts;

/**
 * Short-lived authenticated download access (doc §32).
 */
final class DownloadAuthorization {

	/**
	 * Constructor.
	 *
	 * @param string $url       Download URL (signed or authenticated endpoint).
	 * @param int    $expiresAt Unix timestamp.
	 */
	public function __construct(
		public readonly string $url,
		public readonly int $expiresAt = 0,
	) {}
}
