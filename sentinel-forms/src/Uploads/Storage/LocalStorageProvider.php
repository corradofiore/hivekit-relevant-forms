<?php
/**
 * Local private storage provider (doc §45).
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
 * Free-tier and development storage: files kept OUTSIDE the public media
 * library, under a randomized, access-protected directory.
 */
final class LocalStorageProvider implements FileStorageProviderInterface {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'local';
	}

	/**
	 * {@inheritDoc}
	 *
	 * The browser uploads via a normal multipart POST to an authenticated
	 * plugin endpoint; the provider moves the bytes into the private tree.
	 */
	public function createUploadAuthorization( UploadRequest $request ): UploadAuthorization {
		$url = add_query_arg(
			[
				'action'     => 'sentinel_forms_local_upload',
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
	 * Local downloads always pass through the authenticated admin endpoint;
	 * never a direct filesystem URL.
	 */
	public function getDownloadAuthorization( StoredObject $stored_object ): DownloadAuthorization {
		$url = add_query_arg(
			[
				'action'     => 'sentinel_forms_download_upload',
				'object_key' => rawurlencode( $stored_object->objectKey ),
			],
			admin_url( 'admin-post.php' )
		);

		return new DownloadAuthorization( $url, time() + 300 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function exists( StoredObject $stored_object ): bool {
		return is_file( $this->absolutePath( $stored_object ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( StoredObject $stored_object ): bool {
		$path = $this->absolutePath( $stored_object );

		if ( ! is_file( $path ) ) {
			return true;
		}

		return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- private storage dir outside the media library.
	}

	/**
	 * {@inheritDoc}
	 */
	public function copyToTemporaryFile( StoredObject $stored_object ): ?string {
		$source = $this->absolutePath( $stored_object );

		if ( ! is_file( $source ) ) {
			return null;
		}

		$target = tempnam( sys_get_temp_dir(), 'sf-scan-' );

		if ( false === $target || ! copy( $source, $target ) ) {
			return null;
		}

		return $target;
	}

	/**
	 * Absolute filesystem path for an object key.
	 *
	 * Keys are random, validated against traversal, and rooted in the
	 * protected uploads directory.
	 */
	public function absolutePath( StoredObject $stored_object ): string {
		return self::baseDirectory() . $stored_object->objectKey;
	}

	/**
	 * Move an uploaded temp file into the private tree.
	 *
	 * @param string $tempPath  Uploaded temp file.
	 * @param string $objectKey Target object key.
	 */
	public function store( string $tempPath, string $objectKey ): bool {
		$target = self::baseDirectory() . $objectKey;
		$dir    = dirname( $target );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		return rename( $tempPath, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moving uploads into the private tree.
	}

	/**
	 * Protected base directory (outside wp-content/uploads media tree).
	 */
	public static function baseDirectory(): string {
		$uploads = wp_get_upload_dir();

		$base = $uploads['basedir'] . '/sentinel-forms-private/';

		// Directory is access-protected on creation.
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
			file_put_contents( $base . '.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- access protection for private uploads.
			file_put_contents( $base . 'index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- prevent directory listing fallback.
		}

		return $base;
	}
}
