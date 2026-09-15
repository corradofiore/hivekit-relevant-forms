<?php
/**
 * Upload authorization request DTO.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Contracts;

/**
 * Describes an object a browser is allowed to upload (doc §37).
 */
final class UploadRequest {

	/**
	 * Constructor.
	 *
	 * @param string   $objectKey    Random object key.
	 * @param int      $maxBytes     Hard size limit.
	 * @param string   $contentType  Allowed content type (may be empty).
	 * @param int      $ttlSeconds   Authorization lifetime.
	 * @param int      $formId       Owning form (for provider-side prefixes/logging).
	 */
	public function __construct(
		public readonly string $objectKey,
		public readonly int $maxBytes,
		public readonly string $contentType = '',
		public readonly int $ttlSeconds = 3600,
		public readonly int $formId = 0,
	) {}
}
