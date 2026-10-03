<?php
/**
 * Managed secure storage integration tests (Phase 6, doc §28–32).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Uploads\Domain\Upload;
use RelevantForms\Uploads\Domain\UploadRepository;
use RelevantForms\Uploads\Domain\UploadStatus;
use RelevantForms\Uploads\Scanning\MalwareScanResult;
use RelevantForms\Uploads\Scanning\ScanService;
use RelevantForms\Uploads\UploadService;

/**
 * Authorize → WordPress relay upload → API-mediated storage → backend scan →
 * ownership-checked proxy download, with the plugin acting purely as an API
 * client (no bytes stored on the site, no presigned URLs).
 *
 * @group integration
 */
final class ManagedUploadsFlowTest extends TestCase {

	/**
	 * Requests captured from the plugin to the fake Cloud.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $requests = [];

	/**
	 * Stream relay calls captured from the plugin.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $streams = [];

	/**
	 * Configure the site and install the fake Cloud endpoints.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->requests = [];
		$this->streams  = [];

		update_option(
			'relevant_forms_settings',
			[
				'storage_provider' => 'cloud',
				'cloud_url'        => 'https://cloud.example.com',
				'cloud_api_key'    => 'site-key',
			]
		);

		$connection = new CloudConnection();
		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );
		$connection->enableCapability( CloudConnection::CAP_UPLOADS );

		// Deterministic stand-in for the cURL byte relay (upload/download).
		add_filter(
			'relevant_forms_cloud_stream_transport',
			function ( mixed $override, string $action, string $objectKey, string $path ): mixed {
				$this->streams[] = [
					'action'     => $action,
					'object_key' => $objectKey,
					'path'       => $path,
				];

				if ( 'upload' === $action ) {
					return [
						'status' => 201,
						'data'   => [
							'upload_id'  => 1,
							'object_key' => $objectKey,
							'filename'   => 'report.pdf',
							'size_bytes' => is_file( $path ) ? (int) filesize( $path ) : 0,
							'status'     => 'uploaded',
						],
						'error'  => null,
					];
				}

				if ( 'download' === $action ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test double for the relay sink.
					file_put_contents( $path, 'PDF-BYTES' );

					return true;
				}

				return $override;
			},
			10,
			4
		);

		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array {
				unset( $pre );
				$this->requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				$method = strtoupper( (string) ( $args['method'] ?? 'POST' ) );

				if ( str_contains( $url, '/uploads/status' ) ) {
					return $this->json(
						200,
						[
							'status'     => 'clean',
							'reference'  => 'no-virus',
							'filename'   => 'report.pdf',
							'size_bytes' => 2048,
						]
					);
				}

				if ( 'GET' === $method && 1 === preg_match( '#/uploads/scan/(\d+)$#', $url ) ) {
					return $this->json(
						200,
						[
							'status'    => 'clean',
							'reference' => 'no-virus',
							'message'   => '',
						]
					);
				}

				if ( str_contains( $url, '/uploads/scan' ) ) {
					return $this->json(
						202,
						[
							'scan_request_id' => '77',
							'status'          => 'queued',
						]
					);
				}

				return $this->json( 500, [] );
			},
			10,
			3
		);
	}

	/**
	 * The full managed lifecycle: WordPress relay upload, API-mediated
	 * storage, backend scan, ownership-checked proxy download.
	 */
	public function testManagedUploadLifecycleIsDelegatedToTheCloud(): void {
		$form    = $this->makeFileForm();
		$service = new UploadService();

		$result = $service->authorize( $form, 'f_file', 'report.pdf', 'application/pdf', 1234 );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 'POST', $result['authorization']->method );
		$this->assertStringContainsString( 'admin-post.php', $result['authorization']->url );
		$this->assertStringContainsString( 'action=relevant_forms_local_upload', $result['authorization']->url );

		$uploadId = (int) $result['upload_id'];
		$upload   = ( new UploadRepository() )->find( $uploadId );

		$this->assertInstanceOf( Upload::class, $upload );
		$this->assertSame( 'cloud', $upload->provider );

		// The WordPress relay stores the bytes through the API — no storage
		// credentials or presigned URLs exist on the site.
		$tempPath = wp_tempnam( 'relevant-upload' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- building the payload fixture.
		file_put_contents( $tempPath, 'PDF-BYTES' );

		$stored = $service->storeLocalUpload( $uploadId, (string) $result['token'], $tempPath, 'application/pdf' );

		$this->assertArrayNotHasKey( 'error', $stored );
		$this->assertSame( UploadStatus::QUARANTINED, $stored['upload']->status );
		$this->assertSame( 'upload', $this->streams[0]['action'] ?? null );
		$this->assertSame( $upload->objectKey, $this->streams[0]['object_key'] ?? null );

		// Scanning is delegated: no bytes traverse WordPress (doc §28).
		$scan = ( new ScanService() )->scanUpload( $uploadId );

		$this->assertTrue( $scan['submitted'] ?? false );

		$upload = ( new UploadRepository() )->find( $uploadId );
		$this->assertSame( UploadStatus::SCANNING, $upload->status );
		$this->assertSame( 'backend', $upload->scanEngine );
		$this->assertSame( '77', $upload->scanReference );

		// The deep-scan submission event is recorded (upload not attached
		// to an entry yet, so it is queried by event name).
		global $wpdb;
		$submitted = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}relevant_events WHERE event = %s", 'upload_deep_scan_submitted' )
		);
		$this->assertSame( 1, $submitted );

		// Polling applies the backend outcome.
		ScanService::pollBackendScans();

		$upload = ( new UploadRepository() )->find( $uploadId );
		$this->assertSame( UploadStatus::CLEAN, $upload->status );

		// Downloads stay behind the WordPress proxy (doc §32): the API checks
		// ownership, WordPress checks capability + nonce.
		$download = $service->authorizeDownload( $uploadId );

		$this->assertArrayNotHasKey( 'error', $download );
		$this->assertStringContainsString( 'admin-post.php', $download['download']->url );
		$this->assertStringContainsString( 'action=relevant_forms_download_upload', $download['download']->url );
		$this->assertStringNotContainsString( 'managed.example', $download['download']->url );
	}

	/**
	 * Without the capability the site stays local (v2 §7–8).
	 */
	public function testManagedStorageFallsBackToLocalWithoutConsent(): void {
		$connection = new CloudConnection();
		$connection->disableCapability( CloudConnection::CAP_UPLOADS );

		$form   = $this->makeFileForm();
		$result = ( new UploadService() )->authorize( $form, 'f_file', 'report.pdf', 'application/pdf', 1234 );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 'POST', $result['authorization']->method );
		$this->assertStringContainsString( 'action=relevant_forms_local_upload', $result['authorization']->url );

		$upload = ( new UploadRepository() )->find( (int) $result['upload_id'] );
		$this->assertSame( 'local', $upload->provider );

		// No relay or Cloud request was made without consent.
		$this->assertSame( [], $this->streams );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Backend rejection keeps the file quarantined (fail closed).
	 */
	public function testManagedScanFailuresKeepTheFileQuarantined(): void {
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			function (): array {
				return $this->json( 502, [] );
			},
			10,
			3
		);

		$form    = $this->makeFileForm();
		$service = new UploadService();

		$result   = $service->authorize( $form, 'f_file', 'report.pdf', 'application/pdf', 10 );
		$uploadId = (int) $result['upload_id'];

		$tempPath = wp_tempnam( 'relevant-upload' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- building the payload fixture.
		file_put_contents( $tempPath, 'PDF-BYTES' );

		$stored = $service->storeLocalUpload( $uploadId, (string) $result['token'], $tempPath, 'application/pdf' );
		$this->assertArrayNotHasKey( 'error', $stored );

		$scan = ( new ScanService() )->scanUpload( $uploadId );

		$this->assertSame( MalwareScanResult::ERROR, $scan['result']->outcome );

		$upload = ( new UploadRepository() )->find( $uploadId );
		$this->assertSame( UploadStatus::QUARANTINED, $upload->status );
		$this->assertSame( 'error', $upload->scanStatus );
		$this->assertFalse( UploadStatus::isDownloadable( $upload->status, false ) );
	}

	/**
	 * Build a JSON HTTP response envelope for the fake Cloud.
	 *
	 * @param int                  $status HTTP status.
	 * @param array<string, mixed> $data   Response data.
	 * @return array<string, mixed>
	 */
	private function json( int $status, array $data ): array {
		return [
			'response' => [ 'code' => $status ],
			'body'     => (string) wp_json_encode(
				[
					'success' => true,
					'data'    => $data,
				]
			),
		];
	}

	/**
	 * Build a published form with a default-policy file field.
	 */
	private function makeFileForm(): Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Uploads' )->withFields(
			[
				[
					'id'    => 'f_file',
					'type'  => 'file',
					'label' => 'Files',
				],
			]
		);

		$id   = $repository->create( 'Uploads', $schema, 'published' );
		$form = $repository->findPublished( $id );

		$this->assertNotNull( $form );

		return $form;
	}

	/**
	 * Tear down filters.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'relevant_forms_cloud_stream_transport' );
		parent::tearDown();
	}
}
