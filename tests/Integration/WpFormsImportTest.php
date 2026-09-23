<?php
/**
 * WPForms scanner + importer integration tests (doc §46–47).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\ImportController;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Import\WpFormsForm;
use RelevantForms\Import\WpFormsImporter;
use RelevantForms\Import\WpFormsScanner;
use RelevantForms\Tests\Integration\Support\RedirectedException;

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
						'type'  => 'signature',
						'label' => 'Sign',
					],
				],
			],
			'Signatures'
		);

		$forms = ( new WpFormsScanner() )->forms();
		$row   = ( new WpFormsImporter() )->import( $forms[0] );

		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 0, $row['form_id'] );
		$this->assertNotSame( '', $row['error'] );
	}

	/**
	 * Conditional logic is converted into visibility rules and persisted.
	 */
	public function testImportConvertsConditionalLogic(): void {
		$this->createWpFormsForm(
			[
				'fields'   => [
					'1' => [
						'id'      => '1',
						'type'    => 'select',
						'label'   => 'Plan',
						'choices' => [
							'1' => [
								'label' => 'Pro',
								'value' => 'pro',
							],
							'2' => [
								'label' => 'Free',
							],
						],
					],
					'2' => [
						'id'               => '2',
						'type'             => 'text',
						'label'            => 'Company name',
						'conditional_type' => 'show',
						'conditionals'     => [
							'1' => [
								'1' => [
									'field'    => '1',
									'operator' => '==',
									'value'    => '1',
								],
							],
						],
					],
				],
				'settings' => [ 'form_title' => 'Conditional' ],
			],
			'Conditional'
		);

		$forms = ( new WpFormsScanner() )->forms();

		$this->assertCount( 1, $forms );

		// Cleanly convertible conditions no longer force a review.
		$this->assertSame( WpFormsForm::STATUS_COMPATIBLE, $forms[0]->analysis()['status'] );

		$row  = ( new WpFormsImporter() )->import( $forms[0] );
		$form = ( new FormRepository() )->find( (int) $row['form_id'] );

		$this->assertNotNull( $form );

		$fields = $form->schema->fields();

		$this->assertSame(
			[
				'default' => 'hidden',
				'groups'  => [
					[
						[
							'field'    => '1',
							'operator' => 'is',
							'value'    => 'pro',
						],
					],
				],
			],
			$fields[1]['visibility']
		);

		$this->assertSame( [], $row['skipped'] );
		$this->assertStringNotContainsString( 'could not be imported', implode( ' ', $row['notes'] ) );
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
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_import_wpforms' );

		add_filter(
			'relevant_forms_redirect',
			static function ( string $url ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
				throw new RedirectedException( $url );
			}
		);

		try {
			ImportController::handle();
			$this->fail( 'The controller should redirect.' );
		} catch ( RedirectedException $redirected ) {
			$this->assertStringContainsString( 'page=relevant-import', $redirected->url );
			$this->assertStringContainsString( 'imported=1', $redirected->url );
		} finally {
			remove_all_filters( 'relevant_forms_redirect' );
			unset( $_REQUEST['_wpnonce'] );
		}

		$report = WpFormsImporter::lastReport();

		$this->assertCount( 1, $report['rows'] );
		$this->assertSame( 'imported', $report['rows'][0]['status'] );

		$form = ( new FormRepository() )->find( (int) $report['rows'][0]['form_id'] );
		$this->assertNotNull( $form );
	}

	/**
	 * Payment fields import with amounts, currency and the reconnect note.
	 */
	public function testImportMapsPaymentFieldsWithCurrency(): void {
		update_option( 'wpforms_settings', [ 'currency' => 'EUR' ] );

		$this->createWpFormsForm(
			[
				'fields'   => [
					'0' => [
						'id'     => '0',
						'type'   => 'payment-single',
						'label'  => 'Deposit',
						'format' => 'single',
						'price'  => '25',
					],
					'1' => [
						'id'      => '1',
						'type'    => 'payment-select',
						'label'   => 'Merch',
						'choices' => [
							'1' => [
								'label' => 'T-shirt',
								'value' => '25.00',
							],
							'2' => [
								'label' => 'Cap',
								'value' => '10.00',
							],
						],
					],
					'2' => [
						'id'    => '2',
						'type'  => 'payment-total',
						'label' => 'Total',
					],
				],
				'settings' => [ 'form_title' => 'Checkout' ],
			],
			'Checkout'
		);

		try {
			$forms = ( new WpFormsScanner() )->forms();

			$this->assertCount( 1, $forms );
			$this->assertSame( WpFormsForm::STATUS_COMPATIBLE, $forms[0]->analysis()['status'] );

			$row = ( new WpFormsImporter() )->import( $forms[0] );

			$this->assertSame( 'imported', $row['status'] );
			$this->assertSame( 3, $row['imported_fields'] );
			$this->assertStringContainsString( 'connect Stripe', implode( ' ', $row['notes'] ) );

			$form   = ( new FormRepository() )->find( (int) $row['form_id'] );
			$schema = $form->schema;

			$this->assertSame( 'eur', $schema->currency() );

			$fields = $schema->fields();
			$this->assertSame( 'payment-single', $fields[0]['type'] );
			$this->assertSame( '25', $fields[0]['amount'] );
			$this->assertSame( 'payment-select', $fields[1]['type'] );
			$this->assertSame( [ 'T-shirt|25.00', 'Cap|10.00' ], $fields[1]['choices'] );
			$this->assertSame( 'payment-total', $fields[2]['type'] );
		} finally {
			delete_option( 'wpforms_settings' );
		}
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
