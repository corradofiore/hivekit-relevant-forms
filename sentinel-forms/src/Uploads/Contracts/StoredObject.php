<?php
/**
 * Stored object reference DTO.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Contracts;

/**
 * References an object in storage without exposing raw URLs as identifiers
 * (doc §43).
 */
final class StoredObject {

	/**
	 * Constructor.
	 *
	 * @param string   $objectKey    Random object key.
	 * @param string   $storageLocation Bucket or directory identifier.
	 * @param int      $sizeBytes    Expected size (0 = unknown).
	 * @param string   $contentType  Expected content type (may be empty).
	 */
	public function __construct(
		public readonly string $objectKey,
		public readonly string $storageLocation = '',
		public readonly int $sizeBytes = 0,
		public readonly string $contentType = '',
	) {}
}
