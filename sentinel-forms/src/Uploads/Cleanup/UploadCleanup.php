<?php
/**
 * Upload lifecycle cleanup (doc §40, Phase 3).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Cleanup;

use SentinelForms\Events\EventRecorder;
use SentinelForms\Uploads\Contracts\StoredObject;
use SentinelForms\Uploads\Domain\Upload;
use SentinelForms\Uploads\Domain\UploadRepository;
use SentinelForms\Uploads\Domain\UploadStatus;
use SentinelForms\Uploads\Storage\LocalStorageProvider;
use SentinelForms\Uploads\Storage\S3CompatibleProvider;

/**
 * Removes abandoned uploads: authorized but never uploaded/attached
 * within the grace period (default 24 hours).
 */
final class UploadCleanup {

	/**
	 * Grace period SQL interval for abandoned pending uploads.
	 */
	private const ABANDONED_AFTER = '24 HOUR';

	/**
	 * Register the cron callback.
	 */
	public static function register(): void {
		add_action( 'sentinel_forms_cleanup_uploads', [ self::class, 'run' ] );
	}

	/**
	 * Run one cleanup pass.
	 */
	public static function run(): void {
		$repository = new UploadRepository();

		foreach ( $repository->findPendingOlderThan( self::ABANDONED_AFTER ) as $upload ) {
			self::remove( $upload, $repository );
		}
	}

	/**
	 * Delete the object from storage and expire the row.
	 */
	private static function remove( Upload $upload, UploadRepository $repository ): void {
		$provider = 'local' === $upload->provider ? new LocalStorageProvider() : new S3CompatibleProvider();
		$object   = new StoredObject( $upload->objectKey, $upload->storageLocation );

		$provider->delete( $object );
		$repository->updateStatus( (int) $upload->id, UploadStatus::DELETED );

		EventRecorder::record( null, 'upload_cleanup_removed', [ 'upload_id' => (int) $upload->id ] );
	}
}
