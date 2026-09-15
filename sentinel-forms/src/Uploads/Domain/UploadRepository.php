<?php
/**
 * Upload persistence.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Domain;

/**
 * Repository for wp_sentinel_uploads.
 */
final class UploadRepository {

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * WordPress database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Create an upload record and return its ID.
	 *
	 * @param Upload $upload Upload to persist.
	 */
	public function create( Upload $upload ): int {
		$table = $this->wpdb->prefix . 'sentinel_uploads';
		$now   = current_time( 'mysql', true );

		$this->wpdb->insert(
			$table,
			[
				'form_id'              => $upload->formId,
				'field_id'             => $upload->fieldId,
				'entry_id'             => $upload->entryId,
				'provider'             => $upload->provider,
				'storage_location'     => $upload->storageLocation,
				'object_key'           => $upload->objectKey,
				'original_filename'    => $upload->originalFilename,
				'extension'            => $upload->extension,
				'declared_mime'        => $upload->declaredMime,
				'detected_mime'        => $upload->detectedMime,
				'size_bytes'           => $upload->sizeBytes,
				'checksum_sha256'      => $upload->checksumSha256,
				'status'               => $upload->status,
				'scan_status'          => $upload->scanStatus,
				'scan_engine'          => $upload->scanEngine,
				'scan_reference'       => $upload->scanReference,
				'uploaded_at'          => '' !== $upload->uploadedAt ? $upload->uploadedAt : null,
				'scan_completed_at'    => '' !== $upload->scanCompletedAt ? $upload->scanCompletedAt : null,
				'retention_expires_at' => '' !== $upload->retentionExpiresAt ? $upload->retentionExpiresAt : null,
				'deleted_at'           => '' !== $upload->deletedAt ? $upload->deletedAt : null,
				'created_at'           => $now,
			]
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Find an upload by ID.
	 */
	public function find( int $id ): ?Upload {
		$table = $this->wpdb->prefix . 'sentinel_uploads';

		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal, not user input.

		return $row instanceof \stdClass ? Upload::fromRow( $row ) : null;
	}

	/**
	 * List uploads for an entry.
	 *
	 * @return array<int, Upload>
	 */
	public function findForEntry( int $entryId ): array {
		$table = $this->wpdb->prefix . 'sentinel_uploads';

		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id = %d ORDER BY id ASC", $entryId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal, not user input.

		return array_map( static fn ( object $row ): Upload => Upload::fromRow( $row ), (array) $rows );
	}

	/**
	 * Attach an upload to an entry (only when not already attached).
	 */
	public function attachToEntry( int $uploadId, int $entryId ): bool {
		$upload = $this->find( $uploadId );

		if ( null === $upload || null !== $upload->entryId ) {
			return false;
		}

		$table = $this->wpdb->prefix . 'sentinel_uploads';

		$updated = $this->wpdb->update(
			$table,
			[ 'entry_id' => $entryId ],
			[ 'id' => $uploadId ],
			[ '%d' ],
			[ '%d' ]
		);

		return false !== $updated;
	}

	/**
	 * Update mutable metadata after upload/validation.
	 *
	 * @param array<string, mixed> $data  Columns to update.
	 */
	public function update( int $uploadId, array $data ): bool {
		$table = $this->wpdb->prefix . 'sentinel_uploads';

		$updated = $this->wpdb->update(
			$table,
			$data,
			[ 'id' => $uploadId ]
		);

		return false !== $updated;
	}

	/**
	 * Transition the upload status, honoring the state machine.
	 */
	public function updateStatus( int $uploadId, string $status ): bool {
		$upload = $this->find( $uploadId );

		if ( null === $upload || ! UploadStatus::canTransition( $upload->status, $status ) ) {
			return false;
		}

		return $this->update( $uploadId, [ 'status' => $status ] );
	}
}
