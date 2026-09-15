<?php
/**
 * Storage provider contract (doc §30).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Contracts;

/**
 * Backend for private object storage. The domain layer never assumes S3:
 * Hetzner Object Storage, Backblaze B2, AWS S3 and GCS (S3-compatible mode)
 * all satisfy this interface through S3CompatibleProvider.
 */
interface FileStorageProviderInterface {

	/**
	 * Provider machine name.
	 */
	public function name(): string;

	/**
	 * Issue a short-lived, object-scoped upload authorization.
	 *
	 * Never returns permanent credentials.
	 */
	public function createUploadAuthorization( UploadRequest $request ): UploadAuthorization;

	/**
	 * Issue a short-lived download authorization for a stored object.
	 */
	public function getDownloadAuthorization( StoredObject $stored_object ): DownloadAuthorization;

	/**
	 * Whether the object exists and (when requested) verify size/content type.
	 */
	public function exists( StoredObject $stored_object ): bool;

	/**
	 * Delete the object. Returns true when absent or removed.
	 */
	public function delete( StoredObject $stored_object ): bool;

	/**
	 * Copy the object to a local temporary file for scanning.
	 *
	 * Returns the temp path or null on failure. Callers own cleanup.
	 */
	public function copyToTemporaryFile( StoredObject $stored_object ): ?string;
}
