<?php
/**
 * Scan orchestration: two-stage quarantine workflow (doc §34, §44, Phase 3).
 *
 * Stage 1 — basic: pure-PHP, bounded-memory checks on the WordPress host.
 * Stage 2 — deep: backend queue (plugin acts as API client). Deep local
 * scanners are a Pro extension point; Core contains no deep scanner (T2).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Uploads\Scanning;

use RelevantForms\Events\EventRecorder;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Uploads\Contracts\StoredObject;
use RelevantForms\Uploads\Domain\Upload;
use RelevantForms\Uploads\Domain\UploadRepository;
use RelevantForms\Uploads\Domain\UploadStatus;
use RelevantForms\Uploads\Policy\UploadPolicy;
use RelevantForms\Uploads\Storage\LocalStorageProvider;
use RelevantForms\Uploads\Storage\ProviderFactory;

/**
 * Moves uploads through scanning states and applies scan outcomes.
 * Files stay quarantined until every required stage reports clean.
 */
final class ScanService {

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
		$this->repository = new UploadRepository();
	}

	/**
	 * WP-Cron callback for scheduled scans.
	 */
	public static function handleScheduled( int $uploadId ): void {
		( new self() )->scanUpload( $uploadId );
	}

	/**
	 * WP-Cron callback for polling backend deep-scan results.
	 */
	public static function pollBackendScans(): void {
		( new self() )->poll();
	}

	/**
	 * Queue a poll for pending backend results.
	 */
	public static function schedulePoll( int $delaySeconds = 60 ): void {
		if ( false === wp_next_scheduled( 'relevant_forms_poll_scan_results' ) ) {
			wp_schedule_single_event( time() + $delaySeconds, 'relevant_forms_poll_scan_results' );
		}
	}

	/**
	 * Run the scan pipeline for an upload.
	 *
	 * @return array{error?: string, upload?: Upload, result?: MalwareScanResult, submitted?: bool}
	 */
	public function scanUpload( int $uploadId ): array {
		$upload = $this->repository->find( $uploadId );

		if ( null === $upload ) {
			return [ 'error' => __( 'Upload not found.', 'relevant-forms' ) ];
		}

		if ( ! in_array( $upload->status, [ UploadStatus::QUARANTINED, UploadStatus::SCANNING, UploadStatus::CLEAN, UploadStatus::INFECTED ], true ) ) {
			return [ 'error' => __( 'Upload cannot be scanned in its current state.', 'relevant-forms' ) ];
		}

		if ( in_array( $upload->status, [ UploadStatus::QUARANTINED, UploadStatus::CLEAN, UploadStatus::INFECTED ], true ) ) {
			if ( ! $this->repository->updateStatus( $uploadId, UploadStatus::SCANNING ) ) {
				return [ 'error' => __( 'Upload could not enter the scanning state.', 'relevant-forms' ) ];
			}
		}

		$this->repository->update(
			$uploadId,
			[
				'scan_status' => 'scanning',
				'scan_engine' => 'php-basic',
			]
		);

		// Managed secure storage: bytes never traverse this site. The
		// backend scans its own object (doc §28).
		if ( 'cloud' === ProviderFactory::make()->name() ) {
			$gateway = ScanGatewayFactory::make();

			if ( null === $gateway ) {
				return $this->applyOutcome( $uploadId, MalwareScanResult::error( 'backend', __( 'Managed storage requires the deep-scan backend.', 'relevant-forms' ) ) );
			}

			$externalId = $gateway->submitManaged( $upload );

			if ( null === $externalId ) {
				return $this->applyOutcome( $uploadId, MalwareScanResult::error( 'backend', __( 'Could not submit the managed scan.', 'relevant-forms' ) ) );
			}

			$this->repository->update(
				$uploadId,
				[
					'scan_status'    => 'scanning',
					'scan_engine'    => 'backend',
					'scan_reference' => $externalId,
				]
			);

			EventRecorder::record(
				$upload->entryId,
				'upload_deep_scan_submitted',
				[
					'upload_id'  => $uploadId,
					'request_id' => $externalId,
				]
			);

			self::schedulePoll();

			return [
				'submitted' => true,
				'upload'    => $this->repository->find( $uploadId ),
			];
		}

		$object = new StoredObject( $upload->objectKey, $upload->storageLocation );
		$local  = $this->localCopy( $upload, $object );

		if ( null === $local ) {
			return $this->applyOutcome( $uploadId, MalwareScanResult::error( 'php-basic', __( 'Could not read the stored object.', 'relevant-forms' ) ) );
		}

		// Stage 1: pure-PHP basic checks, always, in-process.
		$basic = ( new BasicPhpScanner() )->scan( $object, $local );

		if ( ! $basic->isClean() ) {
			$this->discardTemp( $local );

			return $this->applyOutcome( $uploadId, $basic );
		}

		$policy = $this->policyFor( $upload );

		// Basic-only policy: the pure-PHP result releases the file.
		if ( null === $policy || UploadPolicy::SCAN_LEVEL_BASIC === $policy->scanLevel ) {
			$this->discardTemp( $local );

			return $this->applyOutcome( $uploadId, MalwareScanResult::clean( 'php-basic' ) );
		}

		// Stage 2: deep scanning. Backend queue first (doc: complex
		// scanning must happen in our backend).
		$gateway = ScanGatewayFactory::make();

		if ( null !== $gateway ) {
			$externalId = $gateway->submit( $upload, $local );
			$this->discardTemp( $local );

			if ( null === $externalId ) {
				return $this->applyOutcome( $uploadId, MalwareScanResult::error( 'backend', __( 'Could not submit the deep scan.', 'relevant-forms' ) ) );
			}

			$this->repository->update(
				$uploadId,
				[
					'scan_status'    => 'scanning',
					'scan_engine'    => 'backend',
					'scan_reference' => $externalId,
				]
			);

			EventRecorder::record(
				$upload->entryId,
				'upload_deep_scan_submitted',
				[
					'upload_id'  => $uploadId,
					'request_id' => $externalId,
				]
			);

			self::schedulePoll();

			return [
				'submitted' => true,
				'upload'    => $this->repository->find( $uploadId ),
			];
		}

		// Extension fallback (T2): Core ships only the pure-PHP basic
		// scanner, so this branch stays inert unless a site registers a
		// deep scanner through the relevant_forms_malware_scanner filter
		// (Relevant Pro). Deep scanning itself belongs to the Cloud.
		$scanner = ScannerFactory::make();

		if ( 'php-basic' !== $scanner->name() && $scanner->isAvailable() ) {
			$result = $scanner->scan( $object, $local );
			$this->discardTemp( $local );

			return $this->applyOutcome( $uploadId, $result );
		}

		// Fail closed: deep scanning required but unavailable.
		$this->discardTemp( $local );

		return $this->applyOutcome( $uploadId, MalwareScanResult::error( 'backend', __( 'Deep scanning is required but no deep scanner is available.', 'relevant-forms' ) ) );
	}

	/**
	 * Persist a scan outcome and fire events/hooks.
	 *
	 * @return array{error?: string, upload?: Upload, result?: MalwareScanResult}
	 */
	public function applyOutcome( int $uploadId, MalwareScanResult $result ): array {
		$now = current_time( 'mysql', true );

		switch ( $result->outcome ) {
			case MalwareScanResult::CLEAN:
				$this->repository->updateStatus( $uploadId, UploadStatus::CLEAN );
				$columns = [ 'scan_status' => 'clean' ];
				break;

			case MalwareScanResult::INFECTED:
				$this->repository->updateStatus( $uploadId, UploadStatus::INFECTED );
				$columns = [ 'scan_status' => 'infected' ];
				break;

			case MalwareScanResult::SUSPICIOUS:
				$this->repository->updateStatus( $uploadId, UploadStatus::QUARANTINED );
				$columns = [ 'scan_status' => 'suspicious' ];
				break;

			default:
				// ERROR / UNSUPPORTED: the file stays quarantined (doc §44).
				$this->repository->updateStatus( $uploadId, UploadStatus::QUARANTINED );
				$columns = [ 'scan_status' => 'error' ];
				break;
		}

		$this->repository->update(
			$uploadId,
			[
				'scan_status'       => $columns['scan_status'],
				'scan_completed_at' => $now,
				'scan_reference'    => $result->reference,
				'scan_engine'       => $result->engine,
			]
		);

		$upload = $this->repository->find( $uploadId );

		EventRecorder::record(
			$upload instanceof Upload ? $upload->entryId : null,
			'upload_scanned',
			[
				'upload_id' => $uploadId,
				'outcome'   => $result->outcome,
			]
		);

		do_action( 'relevant_forms_upload_scanned', $upload, $result );

		return [
			'upload' => $upload,
			'result' => $result,
		];
	}

	/**
	 * Poll the backend for submitted deep-scan results.
	 */
	private function poll(): void {
		$gateway = ScanGatewayFactory::make();

		if ( null === $gateway ) {
			return;
		}

		foreach ( $this->repository->findAwaitingBackendResults() as $upload ) {
			$result = $gateway->fetch( $upload->scanReference );

			if ( null !== $result ) {
				$this->applyOutcome( (int) $upload->id, $result );
			}
		}
	}

	/**
	 * Resolve the per-field policy for an upload.
	 */
	private function policyFor( Upload $upload ): ?UploadPolicy {
		$form  = ( new FormRepository() )->find( $upload->formId );
		$field = null !== $form ? $form->schema->field( $upload->fieldId ) : null;

		return null !== $field ? UploadPolicy::fromDefinition( $field ) : null;
	}

	/**
	 * Produce a local temp copy of the object for the scanner.
	 */
	private function localCopy( Upload $upload, StoredObject $stored_object ): ?string {
		// Local copies are produced for local uploads; extension providers
		// (Pro) copy through their own implementation (T1).
		$provider = 'local' === $upload->provider ? new LocalStorageProvider() : ProviderFactory::make();

		return $provider->copyToTemporaryFile( $stored_object );
	}

	/**
	 * Remove a temp scan copy when it still exists.
	 */
	private function discardTemp( string $local ): void {
		if ( is_file( $local ) ) {
			unlink( $local ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp scan copy cleanup.
		}
	}
}
