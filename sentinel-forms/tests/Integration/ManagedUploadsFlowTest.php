<?php
/**
 * Managed secure storage integration tests (Phase 6, doc §28–32).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Cloud\CloudConnection;
use SentinelForms\Forms\Form;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormSchema;
use SentinelForms\Uploads\Domain\Upload;
use SentinelForms\Uploads\Domain\UploadRepository;
use SentinelForms\Uploads\Domain\UploadStatus;
use SentinelForms\Uploads\Scanning\MalwareScanResult;
use SentinelForms\Uploads\Scanning\ScanService;
use SentinelForms\Uploads\UploadService;

/**
 * Authorize → direct-to-managed-storage → complete → backend scan → signed
 * download, with the plugin acting purely as an API client.
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
	 * Configure the site and install the fake Cloud endpoints.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->requests = [];

		update_option(
			'sentinel_forms_settings',
			[
				'storage_provider' => 'cloud',
				'cloud_url'        => 'https://cloud.example.com',
				'cloud_api_key'    => 'site-key',
				'scan_backend_url' => 'https://cloud.example.com',
				'scan_backend_key' => 'backend-key',
			]
		);

		$connection = new CloudConnection();
		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );
		$connection->enableCapability( CloudConnection::CAP_UPLOADS );

		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array {
				unset( $pre );
				$this->requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				$method = strtoupper( (string) ( $args['method'] ?? 'POST' ) );

				if ( str_contains( $url, '/uploads/authorize' ) ) {
					$body = json_decode( (string) ( $args['body'] ?? '{}' ), true );

					return $this->json(
						201,
						[
							'upload_id'  => 1,
							'object_key' => (string) ( $body['object_key'] ?? '' ),
							'method'     => 'PUT',
							'url'        => 'https://managed.example/put?X-Amz-Signature=put',
							'headers'    => [ 'Content-Type' => (string) ( $body['declared_mime'] ?? '' ) ],
							'expires_in' => 900,
						]
					);
				}

				if ( str_contains( $url, '/uploads/complete' ) ) {
					return $this->json(
						200,
						[
							'status'     => 'uploaded',
							'size_bytes' => 2048,
						]
					);
				}

				if ( str_contains( $url, '/uploads/status' ) ) {
					return $this->json(
						200,
						[
							'status'       => 'clean',
							'reference'    => 'no-virus',
							'filename'     => 'report.pdf',
							'size_bytes'   => 2048,
							'download_url' => 'https://managed.example/get?X-Amz-Signature=get',
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
	 * The full managed lifecycle, from authorization to signed download.
	 */
	public function testManagedUploadLifecycleIsDelegatedToTheCloud(): void {
		$form    = $this->makeFileForm();
		$service = new UploadService();

		$result = $service->authorize( $form, 'f_file', 'report.pdf', 'application/pdf', 1234 );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 'PUT', $result['authorization']->method );
		$this->assertStringContainsString( 'managed.example/put', $result['authorization']->url );

		$uploadId = (int) $result['upload_id'];
		$upload   = ( new UploadRepository() )->find( $uploadId );

		$this->assertInstanceOf( Upload::class, $upload );
		$this->assertSame( 'cloud', $upload->provider );

		// Completion verifies the object through the Cloud.
		$completed = $service->completeRemoteUpload( $uploadId, (string) $result['token'] );

		$this->assertArrayNotHasKey( 'error', $completed );
		$this->assertSame( UploadStatus::QUARANTINED, $completed['upload']->status );

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
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sentinel_events WHERE event = %s", 'upload_deep_scan_submitted' )
		);
		$this->assertSame( 1, $submitted );

		// Polling applies the backend outcome.
		ScanService::pollBackendScans();

		$upload = ( new UploadRepository() )->find( $uploadId );
		$this->assertSame( UploadStatus::CLEAN, $upload->status );

		// Signed downloads come from managed storage (doc §32).
		$download = $service->authorizeDownload( $uploadId );

		$this->assertArrayNotHasKey( 'error', $download );
		$this->assertStringContainsString( 'X-Amz-Signature=get', $download['download']->url );
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

		$upload = ( new UploadRepository() )->find( (int) $result['upload_id'] );
		$this->assertSame( 'local', $upload->provider );

		// No authorization request reached the Cloud.
		foreach ( $this->requests as $request ) {
			$this->assertStringNotContainsString( '/uploads/authorize', (string) $request['url'] );
		}
	}

	/**
	 * Backend rejection keeps the file quarantined (fail closed).
	 */
	public function testManagedScanFailuresKeepTheFileQuarantined(): void {
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array {
				unset( $pre, $args );

				if ( str_contains( $url, '/uploads/authorize' ) ) {
					return $this->json(
						201,
						[
							'object_key' => 'sites/x/forms/1/uploads/x.pdf',
							'method'     => 'PUT',
							'url'        => 'https://managed.example/put',
							'headers'    => [],
							'expires_in' => 900,
						]
					);
				}

				if ( str_contains( $url, '/uploads/complete' ) ) {
					return $this->json(
						200,
						[
							'status'     => 'uploaded',
							'size_bytes' => 10,
						]
					);
				}

				return $this->json( 502, [] );
			},
			10,
			3
		);

		$form    = $this->makeFileForm();
		$service = new UploadService();

		$result   = $service->authorize( $form, 'f_file', 'report.pdf', 'application/pdf', 10 );
		$uploadId = (int) $result['upload_id'];

		$service->completeRemoteUpload( $uploadId, (string) $result['token'] );

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
		parent::tearDown();
	}
}
