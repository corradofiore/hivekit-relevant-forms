<?php
/**
 * Storage provider factory.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Storage;

use SentinelForms\Support\Logger;
use SentinelForms\Uploads\Contracts\FileStorageProviderInterface;

/**
 * Builds the configured storage provider, failing over to local storage
 * when the s3 provider is unavailable (explicit, logged fallback — doc §44).
 */
final class ProviderFactory {

	/**
	 * Create the active provider.
	 */
	public static function make(): FileStorageProviderInterface {
		if ( 's3' === StorageConfig::provider() ) {
			if ( StorageConfig::isS3Configured() ) {
				return new S3CompatibleProvider();
			}

			Logger::warning( 'S3 storage selected but not configured; falling back to local storage.' );
		}

		return new LocalStorageProvider();
	}
}
