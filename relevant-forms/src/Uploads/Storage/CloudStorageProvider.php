<?php
/**
 * Relevant Managed Secure Storage provider (Phase 6, doc §28–32).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Uploads\Storage;

use RelevantForms\Cloud\CloudClient;
use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Support\Logger;
use RelevantForms\Uploads\Contracts\DownloadAuthorization;
use RelevantForms\Uploads\Contracts\FileStorageProviderInterface;
use RelevantForms\Uploads\Contracts\StoredObject;
use RelevantForms\Uploads\Contracts\UploadAuthorization;
use RelevantForms\Uploads\Contracts\UploadRequest;

/**
 * Delegates storage to the API: uploads are received by WordPress (the
 * browser posts them like a local upload) and relayed server-side to the
 * API, which writes them to both buckets; downloads stream back through
 * the API with a tenant ownership check. The site never holds storage
 * credentials and never talks to the buckets directly.
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
	 * Cloud uploads follow the same browser contract as local ones: the
	 * browser POSTs the file to the WordPress endpoint, which then relays
	 * the bytes to the API server-side.
	 */
	public function createUploadAuthorization( UploadRequest $request ): UploadAuthorization {
		$url = add_query_arg(
			[
				'action'     => 'relevant_forms_local_upload',
				'object_key' => rawurlencode( $request->objectKey ),
				'form_id'    => $request->formId,
				'max_bytes'  => $request->maxBytes,
			],
			admin_url( 'admin-post.php' )
		);

		return new UploadAuthorization(
			'POST',
			$url,
			[ 'Content-Type' => 'multipart/form-data' ],
			time() + $request->ttlSeconds,
			$request->objectKey,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Verification doubles as completion: the backend confirms the object
	 * landed in managed storage and meters its size.
	 */
	public function exists( StoredObject $stored_object ): bool {
		$data = $this->client()->managedUploadStatus( $stored_object->objectKey );

		if ( null === $data ) {
			Logger::warning( 'Managed upload lookup failed', [ 'object_key' => $stored_object->objectKey ] );

			return false;
		}

		return in_array( (string) ( $data['status'] ?? '' ), [ 'uploaded', 'scanning', 'clean' ], true );
	}

	/**
	 * Relay one received file to the API, which writes it to both buckets
	 * and verifies the primary copy.
	 *
	 * @param string $objectKey    Client object key.
	 * @param string $filename     Original filename.
	 * @param string $declaredMime Declared MIME type.
	 * @param string $tempPath     Local temp file with the bytes.
	 */
	public function storeFromPath( string $objectKey, string $filename, string $declaredMime, string $tempPath ): bool {
		$result = $this->client()->uploadManagedFile( $objectKey, $filename, $declaredMime, $tempPath );

		if ( $result['status'] < 200 || $result['status'] >= 300 ) {
			Logger::warning(
				'Managed upload relay rejected',
				[
					'object_key' => $objectKey,
					'status'     => $result['status'],
					'error'      => $result['error']['code'] ?? 'unknown',
				]
			);

			return false;
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getDownloadAuthorization( StoredObject $stored_object ): DownloadAuthorization {
		// Downloads always pass back through the authenticated WordPress
		// endpoint, which streams from the API; never a direct bucket URL.
		$url = add_query_arg(
			[
				'action'     => 'relevant_forms_download_upload',
				'object_key' => rawurlencode( $stored_object->objectKey ),
			],
			admin_url( 'admin-post.php' )
		);

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
		$path = wp_tempnam( 'relevant-managed' );

		if ( ! $path ) {
			return null;
		}

		if ( ! $this->client()->downloadManagedContent( $stored_object->objectKey, $path ) ) {
			Logger::warning( 'Managed download failed', [ 'object_key' => $stored_object->objectKey ] );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- discard a failed temp copy.
			@unlink( $path );

			return null;
		}

		return $path;
	}

	/**
	 * Configured Cloud client.
	 */
	private function client(): CloudClient {
		return new CloudClient( CloudConfig::apiBase(), CloudConfig::key() );
	}
}
