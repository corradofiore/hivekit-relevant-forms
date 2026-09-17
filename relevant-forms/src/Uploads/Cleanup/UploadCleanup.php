<?php
/**
 * Upload lifecycle cleanup (doc §40, Phase 3).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Uploads\Cleanup;

use RelevantForms\Events\EventRecorder;
use RelevantForms\Uploads\Contracts\StoredObject;
use RelevantForms\Uploads\Domain\Upload;
use RelevantForms\Uploads\Domain\UploadRepository;
use RelevantForms\Uploads\Domain\UploadStatus;
use RelevantForms\Uploads\Storage\LocalStorageProvider;
use RelevantForms\Uploads\Storage\ProviderFactory;

/**
 * Removes abandoned uploads: authorized but never uploaded/attached
 * within the grace period (default 24 hours).
 */
final class UploadCleanup {

	/**
	 * Grace period for abandoned pending uploads.
	 */
	private const ABANDONED_AFTER = DAY_IN_SECONDS;

	/**
	 * Register the cron callback.
	 */
	public static function register(): void {
		add_action( 'relevant_forms_cleanup_uploads', [ self::class, 'run' ] );
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
		// Managed (cloud) uploads delete through the Cloud API; extension
		// providers resolve through the factory filter (T1).
		$provider = 'local' === $upload->provider ? new LocalStorageProvider() : ProviderFactory::make();
		$object   = new StoredObject( $upload->objectKey, $upload->storageLocation );

		$provider->delete( $object );
		$repository->updateStatus( (int) $upload->id, UploadStatus::DELETED );

		EventRecorder::record( null, 'upload_cleanup_removed', [ 'upload_id' => (int) $upload->id ] );
	}
}
