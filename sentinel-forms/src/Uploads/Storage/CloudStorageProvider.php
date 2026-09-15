<?php
/**
 * Sentinel Managed Secure Storage provider (Phase 6, doc §28–32).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Storage;

use SentinelForms\Cloud\CloudClient;
use SentinelForms\Cloud\CloudConfig;
use SentinelForms\Support\Logger;
use SentinelForms\Uploads\Contracts\DownloadAuthorization;
use SentinelForms\Uploads\Contracts\FileStorageProviderInterface;
use SentinelForms\Uploads\Contracts\StoredObject;
use SentinelForms\Uploads\Contracts\UploadAuthorization;
use SentinelForms\Uploads\Contracts\UploadRequest;

/**
 * Delegates storage to the backend: constrained upload authorization,
 * completion verification, scan state and signed downloads. The site
 * never holds storage credentials and bytes never traverse WordPress
 * unless an explicit local scan is requested.
 */
final class CloudStorageProvider implements FileStorageProviderInterface {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'cloud';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException When the backend cannot authorize the upload.
	 */
	public function createUploadAuthorization( UploadRequest $request ): UploadAuthorization {
		$data = $this->client()->authorizeManagedUpload(
			[
				'object_key'    => $request->objectKey,
				'filename'      => '' !== $request->filename ? $request->filename : basename( $request->objectKey ),
				'declared_mime' => '' !== $request->contentType ? $request->contentType : 'application/octet-stream',
				'size'          => max( 1, $request->declaredSize ),
			]
		);

		if ( null === $data || ! is_string( $data['url'] ?? null ) || '' === (string) $data['url'] ) {
			throw new \RuntimeException( 'Managed storage did not return an upload authorization.' );
		}

		return new UploadAuthorization(
			(string) ( $data['method'] ?? 'PUT' ),
			(string) $data['url'],
			is_array( $data['headers'] ?? null ) ? array_map( 'strval', $data['headers'] ) : [],
			time() + (int) ( $data['expires_in'] ?? 900 ),
			(string) ( $data['object_key'] ?? $request->objectKey ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Verification doubles as completion: the backend confirms the object
	 * landed in managed storage and meters its size.
	 */
	public function exists( StoredObject $stored_object ): bool {
		$data = $this->client()->completeManagedUpload( $stored_object->objectKey );

		if ( null === $data ) {
			Logger::warning( 'Managed upload completion failed', [ 'object_key' => $stored_object->objectKey ] );

			return false;
		}

		return in_array( (string) ( $data['status'] ?? '' ), [ 'uploaded', 'scanning', 'clean' ], true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException When no signed URL is available.
	 */
	public function getDownloadAuthorization( StoredObject $stored_object ): DownloadAuthorization {
		$data = $this->client()->managedUploadStatus( $stored_object->objectKey );

		$url = is_array( $data ) ? (string) ( $data['download_url'] ?? '' ) : '';

		if ( '' === $url ) {
			throw new \RuntimeException( 'Managed storage did not return a download URL.' );
		}

		return new DownloadAuthorization( $url, time() + 300 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( StoredObject $stored_object ): bool {
		return $this->client()->deleteManagedUpload( $stored_object->objectKey );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Downloads bytes for scanning only when explicitly requested; the
	 * managed scan path does not call this (the backend scans its own
	 * object).
	 */
	public function copyToTemporaryFile( StoredObject $stored_object ): ?string {
		try {
			$download = $this->getDownloadAuthorization( $stored_object );
		} catch ( \Throwable $exception ) {
			Logger::warning(
				'Managed download failed',
				[
					'object_key' => $stored_object->objectKey,
					'exception'  => $exception->getMessage(),
				]
			);

			return null;
		}

		$response = wp_remote_get( $download->url, [ 'timeout' => 60 ] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$path = wp_tempnam( 'sentinel-managed' );

		if ( ! $path ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- bounded temp copy for scanning.
		if ( false === file_put_contents( $path, (string) wp_remote_retrieve_body( $response ) ) ) {
			return null;
		}

		return $path;
	}

	/**
	 * Configured Cloud client.
	 */
	private function client(): CloudClient {
		return new CloudClient( CloudConfig::url(), CloudConfig::key() );
	}
}
