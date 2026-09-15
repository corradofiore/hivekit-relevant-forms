<?php
/**
 * Scan workflow integration tests (Phase 3, two-stage model).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormSchema;
use SentinelForms\Tests\Integration\Support\FakeScanGateway;
use SentinelForms\Uploads\Cleanup\UploadCleanup;
use SentinelForms\Uploads\Domain\UploadRepository;
use SentinelForms\Uploads\Domain\UploadStatus;
use SentinelForms\Uploads\Scanning\MalwareScanResult;
use SentinelForms\Uploads\Scanning\ScanService;
use SentinelForms\Uploads\UploadService;

/**
 * Stage 1 (pure PHP) and Stage 2 (backend gateway) on real WordPress.
 *
 * @group integration
 */
final class ScanFlowTest extends TestCase {

	/**
	 * A basic scan of a benign binary releases the file.
	 */
	public function testBasicCleanReleasesFile(): void {
		$form     = $this->makeFileForm( true, 'basic' );
		$uploadId = $this->quarantinedUpload( $form, $this->png() );

		$result = ( new ScanService() )->scanUpload( $uploadId );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( MalwareScanResult::CLEAN, $result['result']->outcome );

		$upload = ( new UploadRepository() )->find( $uploadId );
		$this->assertSame( UploadStatus::CLEAN, $upload->status );
		$this->assertSame( 'clean', $upload->scanStatus );
		$this->assertSame( 'php-basic', $upload->scanEngine );

		update_option( 'sentinel_forms_settings', [ 'storage_block_unscanned_downloads' => 1 ] );
		$this->assertArrayNotHasKey( 'error', ( new UploadService() )->authorizeDownload( $uploadId ) );
	}

	/**
	 * The EICAR test signature is detected by the pure-PHP stage.
	 */
	public function testBasicScannerFlagsEicar(): void {
		$form     = $this->makeFileForm( true, 'basic' );
		$eicar    = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
		$uploadId = $this->quarantinedUpload( $form, $eicar, 'txt' );

		$result = ( new ScanService() )->scanUpload( $uploadId );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( MalwareScanResult::INFECTED, $result['result']->outcome );

		$upload = ( new UploadRepository() )->find( $uploadId );
		$this->assertSame( UploadStatus::INFECTED, $upload->status );
		$this->assertSame( 'infected', $upload->scanStatus );

		$this->assertArrayHasKey( 'error', ( new UploadService() )->authorizeDownload( $uploadId ) );
	}

	/**
	 * Dangerous text markers make a file suspicious: it stays quarantined.
	 */
	public function testBasicScannerFlagsMarkersAsSuspicious(): void {
		$form     = $this->makeFileForm( true, 'basic' );
		$uploadId = $this->quarantinedUpload( $form, "<html><script>eval('x')</script></html>", 'txt' );

		$result = ( new ScanService() )->scanUpload( $uploadId );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( MalwareScanResult::SUSPICIOUS, $result['result']->outcome );

		$upload = ( new UploadRepository() )->find( $uploadId );
		$this->assertSame( UploadStatus::QUARANTINED, $upload->status );
		$this->assertSame( 'suspicious', $upload->scanStatus );
	}

	/**
	 * Deep policy submits to the gateway and polls the result back.
	 */
	public function testDeepScanSubmitAndPoll(): void {
		$form     = $this->makeFileForm( true, 'deep' );
		$uploadId = $this->quarantinedUpload( $form, $this->png() );

		$gateway = new FakeScanGateway();
		add_filter( 'sentinel_forms_scan_gateway', static fn (): FakeScanGateway => $gateway );

		$result = ( new ScanService() )->scanUpload( $uploadId );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertTrue( $result['submitted'] ?? false );

		$upload = ( new UploadRepository() )->find( $uploadId );
		$this->assertSame( UploadStatus::SCANNING, $upload->status );
		$this->assertSame( 'scanning', $upload->scanStatus );
		$this->assertSame( 'backend', $upload->scanEngine );
		$this->assertSame( 'req-test-1', $upload->scanReference );
		$this->assertNotNull( $gateway->last_submitted );
		$this->assertNotFalse( wp_next_scheduled( 'sentinel_forms_poll_scan_results' ) );

		// Backend reports clean → poll applies it.
		$gateway->next_fetch = MalwareScanResult::clean( 'backend' );
		ScanService::pollBackendScans();

		$upload = ( new UploadRepository() )->find( $uploadId );
		$this->assertSame( UploadStatus::CLEAN, $upload->status );
		$this->assertSame( 'clean', $upload->scanStatus );
	}

	/**
	 * An infected backend result blocks the file.
	 */
	public function testDeepScanInfectedBlocksFile(): void {
		$form     = $this->makeFileForm( true, 'deep' );
		$uploadId = $this->quarantinedUpload( $form, $this->png() );

		$gateway             = new FakeScanGateway();
		$gateway->next_fetch = MalwareScanResult::infected( 'backend', 'Trojan.Test' );
		add_filter( 'sentinel_forms_scan_gateway', static fn (): FakeScanGateway => $gateway );

		( new ScanService() )->scanUpload( $uploadId );
		ScanService::pollBackendScans();

		$upload = ( new UploadRepository() )->find( $uploadId );
		$this->assertSame( UploadStatus::INFECTED, $upload->status );
		$this->assertSame( 'Trojan.Test', $upload->scanReference );

		$this->assertArrayHasKey( 'error', ( new UploadService() )->authorizeDownload( $uploadId ) );
	}

	/**
	 * Deep policy without any deep scanner fails closed (doc §44).
	 */
	public function testDeepScanFailsClosedWithoutBackend(): void {
		$form     = $this->makeFileForm( true, 'deep' );
		$uploadId = $this->quarantinedUpload( $form, $this->png() );

		$result = ( new ScanService() )->scanUpload( $uploadId );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( MalwareScanResult::ERROR, $result['result']->outcome );

		$upload = ( new UploadRepository() )->find( $uploadId );
		$this->assertSame( UploadStatus::QUARANTINED, $upload->status );
		$this->assertSame( 'error', $upload->scanStatus );
	}

	/**
	 * Fields without the scan requirement are marked skipped, never clean.
	 */
	public function testScanSkippedWhenNotRequired(): void {
		$form     = $this->makeFileForm( false, 'basic' );
		$uploadId = $this->quarantinedUpload( $form, $this->png() );

		$this->assertFalse( wp_next_scheduled( 'sentinel_forms_scan_upload', [ $uploadId ] ) );

		$upload = ( new UploadRepository() )->find( $uploadId );
		$this->assertSame( UploadStatus::QUARANTINED, $upload->status );
		$this->assertSame( 'skipped', $upload->scanStatus );
	}

	/**
	 * Abandoned pending uploads are removed by lifecycle cleanup.
	 */
	public function testCleanupRemovesAbandonedPendingUploads(): void {
		global $wpdb;

		$form      = $this->makeFileForm( true, 'basic' );
		$service   = new UploadService();
		$authorize = $service->authorize( $form, 'f_file', 'orphan.pdf', 'application/pdf', 10 );

		$this->assertArrayNotHasKey( 'error', $authorize );

		// Backdate the pending row beyond the 24-hour grace period.
		$table = $wpdb->prefix . 'sentinel_uploads';
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			[ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ) ],
			[ 'id' => $authorize['upload_id'] ],
			[ '%s' ],
			[ '%d' ]
		);

		// A fresh pending upload must survive.
		$fresh = $service->authorize( $form, 'f_file', 'fresh.pdf', 'application/pdf', 10 );
		$this->assertArrayNotHasKey( 'error', $fresh );

		UploadCleanup::run();

		$repository = new UploadRepository();

		$this->assertSame( UploadStatus::DELETED, $repository->find( $authorize['upload_id'] )->status );
		$this->assertSame( UploadStatus::PENDING, $repository->find( $fresh['upload_id'] )->status );
	}

	/**
	 * Build a published form with a file field.
	 *
	 * @param bool   $scanRequired Whether the field requires scanning.
	 * @param string $scanLevel    basic|deep.
	 * @return \SentinelForms\Forms\Form
	 */
	private function makeFileForm( bool $scanRequired, string $scanLevel ): \SentinelForms\Forms\Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Scan uploads' )->withFields(
			[
				[
					'id'            => 'f_file',
					'type'          => 'file',
					'label'         => 'Files',
					'upload_policy' => [
						'scan'       => $scanRequired,
						'scan_level' => $scanLevel,
					],
				],
			]
		);

		$id   = $repository->create( 'Scan uploads', $schema, 'published' );
		$form = $repository->findPublished( $id );

		$this->assertNotNull( $form );

		return $form;
	}

	/**
	 * Authorize + store an upload, returning its ID.
	 *
	 * @param \SentinelForms\Forms\Form $form    Owning form.
	 * @param string                    $content File bytes.
	 * @param string                    $ext     Filename extension (default png).
	 */
	private function quarantinedUpload( \SentinelForms\Forms\Form $form, string $content, string $ext = 'png' ): int {
		$service = new UploadService();

		$authorize = $service->authorize( $form, 'f_file', 'upload.' . $ext, 'application/octet-stream', strlen( $content ) );
		$this->assertArrayNotHasKey( 'error', $authorize );

		$path = tempnam( sys_get_temp_dir(), 'sf-scan-flow-' );
		$this->assertNotFalse( $path );

		file_put_contents( (string) $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		$store = $service->storeLocalUpload( $authorize['upload_id'], $authorize['token'], (string) $path, 'application/octet-stream' );
		$this->assertArrayNotHasKey( 'error', $store );

		if ( is_file( (string) $path ) ) {
			unlink( (string) $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
		}

		return $authorize['upload_id'];
	}

	/**
	 * Minimal PNG bytes.
	 */
	private function png(): string {
		return "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . str_repeat( "\0", 13 ) . str_repeat( "\0", 4 );
	}
}
