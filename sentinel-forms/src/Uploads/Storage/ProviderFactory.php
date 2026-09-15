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
 * Builds the configured storage provider. Core ships local private
 * storage and Sentinel Managed Secure Storage; extensions (Sentinel Pro)
 * may supply bring-your-own providers through the
 * `sentinel_forms_storage_provider` filter. Selecting managed storage
 * without consent falls back to local with an explicit, logged warning
 * (doc §44).
 */
final class ProviderFactory {

	/**
	 * Create the active provider.
	 */
	public static function make(): FileStorageProviderInterface {
		/**
		 * Filter the storage provider. Return an implementation of
		 * FileStorageProviderInterface to replace the built-in one
		 * (e.g. a bring-your-own S3-compatible provider).
		 *
		 * @param FileStorageProviderInterface|null $provider Provider or null for the default.
		 */
		$provider = apply_filters( 'sentinel_forms_storage_provider', null );

		if ( $provider instanceof FileStorageProviderInterface ) {
			return $provider;
		}

		if ( 'cloud' === StorageConfig::provider() ) {
			if ( StorageConfig::isCloudUsable() ) {
				return new CloudStorageProvider();
			}

			Logger::warning( 'Managed storage selected but not available (Cloud not connected or capability disabled); falling back to local storage.' );
		}

		return new LocalStorageProvider();
	}
}
