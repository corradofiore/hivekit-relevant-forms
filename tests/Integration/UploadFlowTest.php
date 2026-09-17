<?php
/**
 * Upload flow integration tests (local storage provider).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Uploads\Contracts\StoredObject;
use RelevantForms\Uploads\Domain\UploadRepository;
use RelevantForms\Uploads\Domain\UploadStatus;
use RelevantForms\Uploads\Storage\LocalStorageProvider;
use RelevantForms\Uploads\UploadService;

/**
 * Authorize → store → quarantine → attach → download (doc §28).
 *
 * @group integration
 */
final class UploadFlowTest extends TestCase {

	/**
	 * Local uploads flow through the full lifecycle.
	 */
	public function testLocalUploadLifecycle(): void {
		$form = $this->makeFileForm();

		$service = new UploadService();

		$result = $service->authorize( $form, 'f_file', 'report.pdf', 'application/pdf', 1234 );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertGreaterThan( 0, $result['upload_id'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['token'] );
		$this->assertSame( 'POST', $result['authorization']->method );
		$this->assertStringContainsString( 'relevant_forms_local_upload', $result['authorization']->url );

		$uploadId = $result['upload_id'];

		$this->assertFalse( $service->verifyToken( $uploadId, 'forged-token' ) );
		$this->assertTrue( $service->verifyToken( $uploadId, $result['token'] ) );

		$tempPath = $this->tempPng();

		$store = $service->storeLocalUpload( $uploadId, $result['token'], $tempPath, 'application/pdf' );

		$this->assertArrayNotHasKey( 'error', $store );
		$this->assertNotNull( $store['upload'] );
		$this->assertSame( UploadStatus::QUARANTINED, $store['upload']->status );
		$this->assertSame( 'image/png', $store['upload']->detectedMime );
		$this->assertGreaterThan( 0, $store['upload']->sizeBytes );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $store['upload']->checksumSha256 );
		$this->assertNotSame( '', $store['upload']->uploadedAt );

		// Stored bytes live in the private tree and exist on disk.
		$provider = new LocalStorageProvider();
		$object   = new StoredObject( $store['upload']->objectKey );
		$this->assertTrue( $provider->exists( $object ) );
		$this->assertStringContainsString( 'relevant-forms-private', $provider->absolutePath( $object ) );

		// A second completion attempt must fail (not pending anymore).
		$again = $service->storeLocalUpload( $uploadId, $result['token'], $tempPath, 'application/pdf' );
		$this->assertArrayHasKey( 'error', $again );

		if ( is_file( $tempPath ) ) {
			unlink( $tempPath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
		}

		// Attach to an entry.
		$entryId = ( new EntryRepository() )->create(
			new Entry(
				null,
				$form->id,
				( new FormRepository() )->createVersion( $form->id, $form->schema ),
				Entry::STATUS_NEW,
				home_url( '/' ),
				'',
				'ua-hash',
				'ip-hash',
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				[ 'f_file' => wp_json_encode( [ $uploadId ] ) ],
			)
		);

		$service->attachToEntry( $entryId, 'f_file', [ $uploadId, 999999 ] );

		$uploads = ( new UploadRepository() )->findForEntry( $entryId );
		$this->assertCount( 1, $uploads );
		$this->assertSame( $entryId, $uploads[0]->entryId );

		// Wrong field must not attach.
		$this->assertFalse( ( new UploadRepository() )->attachToEntry( $uploadId, 123 ) );

		// Download authorization passes through the authenticated endpoint.
		$download = $service->authorizeDownload( $uploadId );
		$this->assertArrayNotHasKey( 'error', $download );
		$this->assertStringContainsString( 'relevant_forms_download_upload', $download['download']->url );
	}

	/**
	 * Authorization enforces the per-field policy.
	 */
	public function testAuthorizeEnforcesPolicy(): void {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Uploads' )->withFields(
			[
				[
					'id'            => 'f_file',
					'type'          => 'file',
					'label'         => 'Files',
					'upload_policy' => [
						'allowed_ext' => [ 'pdf' ],
						'max_size'    => 1024,
						'max_files'   => 1,
					],
				],
			]
		);

		$id   = $repository->create( 'Uploads', $schema, 'published' );
		$form = $repository->findPublished( $id );
		$this->assertNotNull( $form );

		$service = new UploadService();

		$blockedExtension = $service->authorize( $form, 'f_file', 'virus.exe', 'application/octet-stream', 10 );
		$this->assertArrayHasKey( 'error', $blockedExtension );

		$oversized = $service->authorize( $form, 'f_file', 'big.pdf', 'application/pdf', 2048 );
		$this->assertArrayHasKey( 'error', $oversized );

		$unknownField = $service->authorize( $form, 'nope', 'x.pdf', 'application/pdf', 10 );
		$this->assertArrayHasKey( 'error', $unknownField );

		$ok = $service->authorize( $form, 'f_file', 'ok.pdf', 'application/pdf', 500 );
		$this->assertArrayNotHasKey( 'error', $ok );
	}

	/**
	 * Quarantined downloads respect the global unscanned-download policy.
	 */
	public function testQuarantinedDownloadGating(): void {
		$form = $this->makeFileForm();

		$service = new UploadService();

		$result = $service->authorize( $form, 'f_file', 'report.pdf', 'application/pdf', 10 );

		$tempPath = $this->tempPng();
		$store    = $service->storeLocalUpload( $result['upload_id'], $result['token'], $tempPath, 'application/pdf' );
		if ( is_file( $tempPath ) ) {
			unlink( $tempPath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
		}

		$this->assertArrayNotHasKey( 'error', $store );

		update_option(
			'relevant_forms_settings',
			[ 'storage_block_unscanned_downloads' => 1 ]
		);

		$blocked = $service->authorizeDownload( $result['upload_id'] );
		$this->assertArrayHasKey( 'error', $blocked );

		update_option( 'relevant_forms_settings', [] );

		$allowed = $service->authorizeDownload( $result['upload_id'] );
		$this->assertArrayNotHasKey( 'error', $allowed );
	}

	/**
	 * Build a published form with a default-policy file field.
	 *
	 * @return \RelevantForms\Forms\Form
	 */
	private function makeFileForm(): \RelevantForms\Forms\Form {
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
	 * Write a minimal PNG to a temp file and return its path.
	 */
	private function tempPng(): string {
		$path = tempnam( sys_get_temp_dir(), 'rf-upload-' );
		$this->assertNotFalse( $path );

		$png = "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . str_repeat( "\0", 13 ) . str_repeat( "\0", 4 );
		file_put_contents( (string) $path, $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		return (string) $path;
	}
}
