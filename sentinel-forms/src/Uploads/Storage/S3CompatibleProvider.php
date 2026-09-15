<?php
/**
 * S3-compatible object storage provider.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Storage;

use SentinelForms\Uploads\Contracts\DownloadAuthorization;
use SentinelForms\Uploads\Contracts\FileStorageProviderInterface;
use SentinelForms\Uploads\Contracts\StoredObject;
use SentinelForms\Uploads\Contracts\UploadAuthorization;
use SentinelForms\Uploads\Contracts\UploadRequest;

/**
 * Private object storage via any S3-compatible endpoint: Hetzner Object
 * Storage, Backblaze B2, AWS S3, GCS interoperability mode (doc §30–§39).
 */
final class S3CompatibleProvider implements FileStorageProviderInterface {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->client = new S3Client(
			StorageConfig::endpoint(),
			StorageConfig::region(),
			StorageConfig::accessKey(),
			StorageConfig::secretKey(),
			StorageConfig::bucket(),
		);
	}

	/**
	 * Underlying client.
	 *
	 * @var S3Client
	 */
	private S3Client $client;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 's3';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Presigned PUT, scoped to the exact object key, with a constrained
	 * Content-Type when provided. The browser never sees credentials.
	 */
	public function createUploadAuthorization( UploadRequest $request ): UploadAuthorization {
		$headers = [];

		if ( '' !== $request->contentType ) {
			$headers['Content-Type'] = $request->contentType;
		}

		$url = $this->client->presignedUrl( 'PUT', $request->objectKey, $request->ttlSeconds, $headers );

		return new UploadAuthorization(
			'PUT',
			$url,
			$headers,
			time() + $request->ttlSeconds,
			$request->objectKey,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Short-lived presigned GET; objects are private by default.
	 */
	public function getDownloadAuthorization( StoredObject $stored_object ): DownloadAuthorization {
		$ttl = 300;
		$url = $this->client->presignedUrl( 'GET', $stored_object->objectKey, $ttl );

		return new DownloadAuthorization( $url, time() + $ttl );
	}

	/**
	 * {@inheritDoc}
	 */
	public function exists( StoredObject $stored_object ): bool {
		$response = $this->client->request( 'HEAD', $stored_object->objectKey );

		if ( null === $response ) {
			return false;
		}

		return 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( StoredObject $stored_object ): bool {
		$response = $this->client->request( 'DELETE', $stored_object->objectKey );

		if ( null === $response ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return in_array( $code, [ 200, 204, 404 ], true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function copyToTemporaryFile( StoredObject $stored_object ): ?string {
		$authorization = $this->getDownloadAuthorization( $stored_object );
		$target        = tempnam( sys_get_temp_dir(), 'sf-scan-' );

		if ( false === $target ) {
			return null;
		}

		$response = wp_remote_get(
			$authorization->url,
			[
				'timeout'  => 60,
				'stream'   => true,
				'filename' => $target,
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			if ( is_file( $target ) ) {
				unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp scan copy cleanup.
			}

			return null;
		}

		return $target;
	}
}
