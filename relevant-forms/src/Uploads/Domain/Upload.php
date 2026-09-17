<?php
/**
 * Upload entity.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Uploads\Domain;

/**
 * Metadata record for an uploaded object (doc §43).
 */
final class Upload {

	/**
	 * Constructor.
	 *
	 * @param int|null $id                Upload ID (null before persistence).
	 * @param int      $formId            Owning form ID.
	 * @param string   $fieldId           Field ID the file belongs to.
	 * @param int|null $entryId           Attached entry ID (null until submission).
	 * @param string   $provider           provider machine name (local|s3).
	 * @param string   $storageLocation   Bucket/directory identifier.
	 * @param string   $objectKey         Random object key; never the client filename.
	 * @param string   $originalFilename  Client-supplied filename (display only).
	 * @param string   $extension         Sanitized extension.
	 * @param string   $declaredMime      Client-declared MIME (untrusted).
	 * @param string   $detectedMime      Server-detected MIME.
	 * @param int      $sizeBytes         Reported size.
	 * @param string   $checksumSha256    SHA-256 checksum.
	 * @param string   $status            UploadStatus state.
	 * @param string   $scanStatus        Scan outcome (Phase 3).
	 * @param string   $scanEngine        Scanner identifier (Phase 3).
	 * @param string   $scanReference     Scanner-side reference (Phase 3).
	 * @param string   $uploadedAt        MySQL datetime.
	 * @param string   $scanCompletedAt   MySQL datetime.
	 * @param string   $retentionExpiresAt MySQL datetime.
	 * @param string   $deletedAt         MySQL datetime.
	 * @param string   $createdAt         MySQL datetime.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly int $formId,
		public readonly string $fieldId,
		public readonly ?int $entryId,
		public readonly string $provider,
		public readonly string $storageLocation,
		public readonly string $objectKey,
		public readonly string $originalFilename,
		public readonly string $extension,
		public readonly string $declaredMime,
		public readonly string $detectedMime,
		public readonly int $sizeBytes,
		public readonly string $checksumSha256,
		public readonly string $status,
		public readonly string $scanStatus,
		public readonly string $scanEngine,
		public readonly string $scanReference,
		public readonly string $uploadedAt,
		public readonly string $scanCompletedAt,
		public readonly string $retentionExpiresAt,
		public readonly string $deletedAt,
		public readonly string $createdAt,
	) {}

	/**
	 * Hydrate from a database row.
	 *
	 * @param object $row Row with upload columns.
	 */
	public static function fromRow( object $row ): self {
		return new self(
			(int) $row->id,
			(int) $row->form_id,
			(string) $row->field_id,
			null !== $row->entry_id ? (int) $row->entry_id : null,
			(string) $row->provider,
			(string) $row->storage_location,
			(string) $row->object_key,
			(string) $row->original_filename,
			(string) $row->extension,
			(string) $row->declared_mime,
			(string) $row->detected_mime,
			(int) $row->size_bytes,
			(string) $row->checksum_sha256,
			(string) $row->status,
			(string) $row->scan_status,
			(string) $row->scan_engine,
			(string) $row->scan_reference,
			(string) ( $row->uploaded_at ?? '' ),
			(string) ( $row->scan_completed_at ?? '' ),
			(string) ( $row->retention_expires_at ?? '' ),
			(string) ( $row->deleted_at ?? '' ),
			(string) $row->created_at,
		);
	}
}
