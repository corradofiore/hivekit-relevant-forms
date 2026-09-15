<?php
/**
 * WPForms scanner + importer integration tests (doc §46–47).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Admin\Controllers\ImportController;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Import\WpFormsImporter;
use SentinelForms\Import\WpFormsScanner;
use SentinelForms\Tests\Integration\Support\RedirectedException;

/**
 * Read-only scan, draft import, report — on real WordPress records.
 *
 * @group integration
 */
final class WpFormsImportTest extends TestCase {

	/**
	 * The scanner detects leftover WPForms records and estimates compatibility.
	 */
	public function testScannerReportsCompatibilityWithoutTouchingData(): void {
		$payload = $this->payload();

		// Scanner-compat fixture: only supported fields → fully compatible.
		unset( $payload['fields']['3'] );

		$postId = $this->createWpFormsForm( $payload, 'Contact Us' );

		$scanner = new WpFormsScanner();
		$report  = $scanner->report();

		$this->assertTrue( $report['installed'] );
		$this->assertSame( 1, $report['total'] );
		$this->assertSame( 1, $report['compatible'] );
		$this->assertSame( 0, $report['review'] );
		$this->assertSame( 100, $report['compatibility'] );
		$this->assertSame( 1, $report['uploads'] );
		$this->assertArrayHasKey( 'file-upload', $report['field_types'] );
		$this->assertSame( [], $report['forms'][0]['issues'] );

		// Scanner is read-only: the source record is untouched.
		$post = get_post( $postId );
		$this->assertSame( (string) wp_json_encode( $payload ), (string) $post->post_content );
	}

	/**
	 * Import builds a draft with mapped fields, notifications and confirmation.
	 */
	public function testImportCreatesADraftFormWithMappedSchema(): void {
		$payload = $this->payload();
		$postId  = $this->createWpFormsForm( $payload, 'Contact Us' );

		$forms = ( new WpFormsScanner() )->forms();
		$this->assertCount( 1, $forms );

		$row = ( new WpFormsImporter() )->import( $forms[0] );

		$this->assertSame( 'imported', $row['status'] );
		$this->assertGreaterThan( 0, $row['form_id'] );
		$this->assertCount( 1, $row['skipped'] );

		$form = ( new FormRepository() )->find( (int) $row['form_id'] );

		$this->assertNotNull( $form );
		$this->assertSame( 'Contact Us', $form->name );
		$this->assertSame( 'draft', $form->status );

		$fields = $form->schema->fields();
		$this->assertCount( 3, $fields );

		$this->assertSame( '0', $fields[0]['id'] );
		$this->assertSame( 'text', $fields[0]['type'] );
		$this->assertTrue( $fields[0]['required'] );

		$this->assertSame( 'email', $fields[1]['type'] );

		$this->assertSame( 'file', $fields[2]['type'] );
		$this->assertTrue( $fields[2]['upload_policy']['scan'] );
		$this->assertSame( [ 'pdf', 'jpg' ], $fields[2]['upload_policy']['allowed_ext'] );

		$this->assertSame( [ 'sales@example.com' ], $form->schema->notificationEmails() );
		$this->assertSame( 'Thanks for reaching out!', $form->schema->confirmationMessage() );
		$this->assertSame( 'wpforms', $form->schema->metadata()['imported_from'] );
		$this->assertSame( $postId, $form->schema->metadata()['wpforms_form_id'] );

		// Original data untouched.
		$this->assertSame( (string) wp_json_encode( $payload ), (string) get_post( $postId )->post_content );
	}

	/**
	 * Forms without importable fields fail gracefully in the report.
	 */
	public function testImportWithoutImportableFieldsFailsGracefully(): void {
		$this->createWpFormsForm(
			[
				'fields' => [
					[
						'id'    => '0',
						'type'  => 'payment-single',
						'label' => 'Pay',
					],
				],
			],
			'Payments'
		);

		$forms = ( new WpFormsScanner() )->forms();
		$row   = ( new WpFormsImporter() )->import( $forms[0] );

		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 0, $row['form_id'] );
		$this->assertNotSame( '', $row['error'] );
	}

	/**
	 * The admin-post controller imports and stores the migration report.
	 */
	public function testImportControllerStoresReportAndRedirects(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$postId = $this->createWpFormsForm( $this->payload(), 'Contact Us' );

		$_POST                = [
			'wpforms_form_id' => $postId,
		];
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'sentinel_import_wpforms' );

		add_filter(
			'sentinel_forms_redirect',
			static function ( string $url ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
				throw new RedirectedException( $url );
			}
		);

		try {
			ImportController::handle();
			$this->fail( 'The controller should redirect.' );
		} catch ( RedirectedException $redirected ) {
			$this->assertStringContainsString( 'page=sentinel-import', $redirected->url );
			$this->assertStringContainsString( 'imported=1', $redirected->url );
		} finally {
			remove_all_filters( 'sentinel_forms_redirect' );
			unset( $_REQUEST['_wpnonce'] );
		}

		$report = WpFormsImporter::lastReport();

		$this->assertCount( 1, $report['rows'] );
		$this->assertSame( 'imported', $report['rows'][0]['status'] );

		$form = ( new FormRepository() )->find( (int) $report['rows'][0]['form_id'] );
		$this->assertNotNull( $form );
	}

	/**
	 * Canonical payload: two common fields, a file field and an unsupported one.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		return [
			'fields'   => [
				'0' => [
					'id'       => '0',
					'type'     => 'text',
					'label'    => 'Name',
					'required' => '1',
				],
				'1' => [
					'id'    => '1',
					'type'  => 'email',
					'label' => 'Email',
				],
				'2' => [
					'id'         => '2',
					'type'       => 'file-upload',
					'label'      => 'Plans',
					'extensions' => 'pdf,jpg',
				],
				'3' => [
					'id'    => '3',
					'type'  => 'signature',
					'label' => 'Signature',
				],
			],
			'settings' => [
				'form_title'    => 'Contact Us',
				'notifications' => [
					'1' => [ 'email' => 'sales@example.com' ],
				],
				'confirmations' => [
					'1' => [
						'type'    => 'message',
						'message' => 'Thanks for reaching out!',
					],
				],
			],
		];
	}

	/**
	 * Create a WPForms form record (post type `wpforms`, JSON content).
	 *
	 * @param array<string, mixed> $payload Form payload.
	 * @param string               $title   Post title.
	 */
	private function createWpFormsForm( array $payload, string $title ): int {
		$postId = wp_insert_post(
			[
				'post_type'    => 'wpforms',
				'post_title'   => $title,
				'post_content' => (string) wp_json_encode( $payload ),
				'post_status'  => 'publish',
			]
		);

		$this->assertIsInt( $postId );

		return (int) $postId;
	}
}
