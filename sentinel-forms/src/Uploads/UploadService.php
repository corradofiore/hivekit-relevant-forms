<?php
/**
 * Upload orchestration: authorize, complete, attach, download.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads;

use SentinelForms\Events\EventRecorder;
use SentinelForms\Forms\Form;
use SentinelForms\Uploads\Contracts\DownloadAuthorization;
use SentinelForms\Uploads\Contracts\StoredObject;
use SentinelForms\Uploads\Contracts\UploadAuthorization;
use SentinelForms\Uploads\Contracts\UploadRequest;
use SentinelForms\Uploads\Domain\Upload;
use SentinelForms\Uploads\Domain\UploadRepository;
use SentinelForms\Uploads\Domain\UploadStatus;
use SentinelForms\Uploads\Policy\UploadPolicy;
use SentinelForms\Uploads\Storage\LocalStorageProvider;
use SentinelForms\Uploads\Storage\ProviderFactory;
use SentinelForms\Uploads\Storage\StorageConfig;
use SentinelForms\Uploads\Validation\FileValidator;

/**
 * High-level upload workflow (doc §28).
 */
final class UploadService {

	/**
	 * Validator.
	 *
	 * @var FileValidator
	 */
	private FileValidator $validator;

	/**
	 * Repository.
	 *
	 * @var UploadRepository
	 */
	private UploadRepository $repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->validator  = new FileValidator();
		$this->repository = new UploadRepository();
	}

	/**
	 * Authorize a constrained upload and create a pending upload record.
	 *
	 * @return array{error?: string, upload_id?: int, token?: string, authorization?: UploadAuthorization}
	 */
	public function authorize( Form $form, string $fieldId, string $filename, string $declaredMime, int $size ): array {
		$field = $form->schema->field( $fieldId );

		if ( null === $field || 'file' !== (string) ( $field['type'] ?? '' ) ) {
			return [ 'error' => __( 'Invalid upload field.', 'sentinel-forms' ) ];
		}

		$policy         = UploadPolicy::fromDefinition( $field );
		$filename       = $this->validator->sanitizeFilename( $filename );
		$validatorError = $this->validator->validateDeclared( $policy, $filename, $size );

		if ( null !== $validatorError ) {
			return [ 'error' => $validatorError ];
		}

		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$objectKey = $this->objectKey( $form->id, $extension );
		$provider  = ProviderFactory::make();

		$uploadId = $this->repository->create(
			new Upload(
				null,
				$form->id,
				$fieldId,
				null,
				$provider->name(),
				's3' === $provider->name() ? StorageConfig::bucket() : 'local',
				$objectKey,
				$filename,
				$extension,
				$declaredMime,
				'',
				$size,
				'',
				UploadStatus::PENDING,
				'unscanned',
				'',
				'',
				'',
				'',
				'',
				'',
				current_time( 'mysql', true ),
			)
		);

		$authorization = $provider->createUploadAuthorization(
			new UploadRequest(
				$objectKey,
				$policy->maxSize,
				$declaredMime,
				3600,
				$form->id,
			)
		);

		return [
			'upload_id'     => $uploadId,
			'token'         => $this->token( $uploadId, $objectKey ),
			'authorization' => $authorization,
		];
	}

	/**
	 * Verify an upload completion token.
	 */
	public function verifyToken( int $uploadId, string $token ): bool {
		$upload = $this->repository->find( $uploadId );

		return null !== $upload && hash_equals( $this->token( $uploadId, $upload->objectKey ), $token );
	}

	/**
	 * Store locally uploaded bytes into the private tree and record metadata.
	 *
	 * @param string $tempPath Uploaded temp file.
	 * @param string $declaredMime Declared MIME.
	 * @return array{error?: string, upload?: Upload}
	 */
	public function storeLocalUpload( int $uploadId, string $token, string $tempPath, string $declaredMime ): array {
		if ( ! $this->verifyToken( $uploadId, $token ) ) {
			return [ 'error' => __( 'Invalid upload token.', 'sentinel-forms' ) ];
		}

		$upload = $this->repository->find( $uploadId );

		if ( null === $upload || UploadStatus::PENDING !== $upload->status ) {
			return [ 'error' => __( 'Upload is not pending.', 'sentinel-forms' ) ];
		}

		$size = (int) filesize( $tempPath );

		$formRepository = new \SentinelForms\Forms\FormRepository();
		$form           = $formRepository->find( $upload->formId );
		$field          = null !== $form ? $form->schema->field( $upload->fieldId ) : null;
		$policy         = null !== $field ? UploadPolicy::fromDefinition( $field ) : null;

		if ( null !== $policy ) {
			$policyError = $this->validator->validateDeclared( $policy, $upload->originalFilename, $size );

			if ( null !== $policyError ) {
				$this->repository->updateStatus( $uploadId, UploadStatus::REJECTED );

				return [ 'error' => $policyError ];
			}
		}

		$provider = new LocalStorageProvider();

		if ( ! $provider->store( $tempPath, $upload->objectKey ) ) {
			$this->repository->updateStatus( $uploadId, UploadStatus::ERROR );

			return [ 'error' => __( 'Could not store the uploaded file.', 'sentinel-forms' ) ];
		}

		$detectedMime = $this->validator->detectMime( $provider->absolutePath( new StoredObject( $upload->objectKey ) ) );
		$checksum     = hash_file( 'sha256', $provider->absolutePath( new StoredObject( $upload->objectKey ) ) );

		$this->repository->update(
			$uploadId,
			[
				'status'               => UploadStatus::QUARANTINED,
				'uploaded_at'          => current_time( 'mysql', true ),
				'declared_mime'        => $declaredMime,
				'detected_mime'        => $detectedMime,
				'size_bytes'           => $size,
				'checksum_sha256'      => false !== $checksum ? $checksum : '',
				'retention_expires_at' => current_time( 'mysql', true ),
			]
		);

		EventRecorder::record( null, 'upload_quarantined', [ 'upload_id' => $uploadId ] );

		return [ 'upload' => $this->repository->find( $uploadId ) ];
	}

	/**
	 * Mark a remote (direct-to-storage) upload as completed.
	 *
	 * @return array{error?: string, upload?: Upload}
	 */
	public function completeRemoteUpload( int $uploadId, string $token ): array {
		if ( ! $this->verifyToken( $uploadId, $token ) ) {
			return [ 'error' => __( 'Invalid upload token.', 'sentinel-forms' ) ];
		}

		$upload = $this->repository->find( $uploadId );

		if ( null === $upload || UploadStatus::PENDING !== $upload->status ) {
			return [ 'error' => __( 'Upload is not pending.', 'sentinel-forms' ) ];
		}

		$provider = ProviderFactory::make();
		$object   = new StoredObject( $upload->objectKey, $upload->storageLocation );

		if ( ! $provider->exists( $object ) ) {
			return [ 'error' => __( 'Uploaded object was not found in storage.', 'sentinel-forms' ) ];
		}

		$this->repository->update(
			$uploadId,
			[
				'status'      => UploadStatus::QUARANTINED,
				'uploaded_at' => current_time( 'mysql', true ),
			]
		);

		EventRecorder::record( null, 'upload_quarantined', [ 'upload_id' => $uploadId ] );

		return [ 'upload' => $this->repository->find( $uploadId ) ];
	}

	/**
	 * Attach uploads to a stored entry after a successful submission.
	 *
	 * @param int                 $entryId   Entry ID.
	 * @param string              $fieldId   Field ID.
	 * @param array<int, int>     $uploadIds Upload IDs claimed by the submission.
	 */
	public function attachToEntry( int $entryId, string $fieldId, array $uploadIds ): void {
		foreach ( array_unique( array_map( 'absint', $uploadIds ) ) as $uploadId ) {
			$upload = $this->repository->find( $uploadId );

			if ( null === $upload || $upload->fieldId !== $fieldId ) {
				continue;
			}

			$this->repository->attachToEntry( $uploadId, $entryId );
		}
	}

	/**
	 * Authorize an administrator download when policy permits.
	 *
	 * @return array{error?: string, download?: DownloadAuthorization, upload?: Upload}
	 */
	public function authorizeDownload( int $uploadId ): array {
		$upload = $this->repository->find( $uploadId );

		if ( null === $upload ) {
			return [ 'error' => __( 'Upload not found.', 'sentinel-forms' ) ];
		}

		if ( ! UploadStatus::isDownloadable( $upload->status, StorageConfig::allowUnscannedDownload() ) ) {
			return [ 'error' => __( 'This file is not available for download.', 'sentinel-forms' ) ];
		}

		$provider = ProviderFactory::make();
		$object   = new StoredObject( $upload->objectKey, $upload->storageLocation );

		if ( ! $provider->exists( $object ) ) {
			return [ 'error' => __( 'Stored object is missing.', 'sentinel-forms' ) ];
		}

		$download = $provider->getDownloadAuthorization( $object );

		EventRecorder::record( $upload->entryId, 'upload_download_authorized', [ 'upload_id' => $uploadId ] );

		return [
			'download' => $download,
			'upload'   => $upload,
		];
	}

	/**
	 * Random object key (doc §33): never derived from the client filename.
	 */
	private function objectKey( int $formId, string $extension ): string {
		$siteHash = substr( hash( 'sha256', (string) home_url() ), 0, 8 );
		$uuid     = wp_generate_uuid4();

		return sprintf(
			'sites/%s/forms/%d/uploads/%s.%s',
			$siteHash,
			$formId,
			$uuid,
			$extension
		);
	}

	/**
	 * Bearer token binding an upload id to its object key.
	 */
	private function token( int $uploadId, string $objectKey ): string {
		return hash_hmac( 'sha256', $uploadId . '|' . $objectKey, wp_salt( 'auth' ) );
	}
}
