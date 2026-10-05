<?php
/**
 * Form save AJAX flow integration tests: the editor's fetch() posts the
 * builder payload to admin-ajax and gets a JSON envelope back (no reload).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\FormSaveController;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Tests\Integration\Support\AjaxRequests;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FormSaveAjaxTest extends TestCase {

	/**
	 * Editing an existing form saves through AJAX and answers with its ID.
	 */
	public function testAjaxSaveUpdatesTheFormInPlace(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm( 'Contact' );

		$response = $this->dispatch(
			[
				'form_id'     => $formId,
				'form_name'   => 'Renamed Contact',
				'form_status' => 'published',
			]
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( $formId, $response['data']['form_id'] );
		$this->assertFalse( $response['data']['created'] );
		$this->assertSame( 'Form saved.', $response['data']['message'] );
		$this->assertSame( '', $response['data']['warning'] );
		$this->assertSame( 'Renamed Contact', ( new FormRepository() )->find( $formId )->name );
	}

	/**
	 * Creating a form through AJAX reports created=true plus the new ID so
	 * the editor can continue in place.
	 */
	public function testAjaxSaveCreatesTheFormOnFirstSave(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->dispatch(
			[
				'form_id'     => 0,
				'form_name'   => 'New Form',
				'form_status' => 'draft',
			]
		);

		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['created'] );
		$this->assertGreaterThan( 0, $response['data']['form_id'] );
		$this->assertSame( 'Form created.', $response['data']['message'] );
		$this->assertSame( 'New Form', ( new FormRepository() )->find( $response['data']['form_id'] )->name );
	}

	/**
	 * Validation problems come back as a JSON error, not a reloaded page.
	 */
	public function testAjaxSaveReportsValidationErrorsAsJson(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->dispatch(
			[
				'form_id'     => 0,
				'form_name'   => '',
				'form_status' => 'draft',
			]
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_form', $response['data']['code'] );
		$this->assertStringContainsString( 'form name is required', $response['data']['message'] );
		$this->assertSame( [], ( new FormRepository() )->all() );
	}

	/**
	 * An unverified SMS number is kept out of the saved schema and surfaces
	 * as a warning in the JSON envelope.
	 */
	public function testAjaxSaveWarnsWhenTheSmsNumberIsUnverified(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm( 'Priority' );

		$response = $this->dispatch(
			[
				'form_id'      => $formId,
				'form_name'    => 'Priority',
				'form_status'  => 'published',
				'ai_sms_alert' => '1',
				'ai_sms_phone' => '+15551234567',
			]
		);

		$this->assertTrue( $response['success'] );
		$this->assertStringContainsString( 'verify it with the 6-digit code', $response['data']['warning'] );

		$form = ( new FormRepository() )->find( $formId );
		$this->assertSame( '', (string) ( $form->schema->aiPolicy()['sms_phone'] ?? '' ) );
	}

	/**
	 * Users without the manage_options capability are rejected.
	 */
	public function testAjaxSaveRequiresManagerCapability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->dispatch(
			[
				'form_id'     => 0,
				'form_name'   => 'Sneaky',
				'form_status' => 'draft',
			]
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( [], ( new FormRepository() )->all() );
	}

	/**
	 * POST to the AJAX endpoint with a valid nonce.
	 *
	 * @param array<string, mixed> $payload Editor payload.
	 * @return array<string, mixed> Decoded response body.
	 */
	private function dispatch( array $payload ): array {
		$_POST                = $payload;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_form_save' );

		return AjaxRequests::dispatch( [ FormSaveController::class, 'handleAjax' ] );
	}

	/**
	 * Create a published form.
	 */
	private function makeForm( string $name ): int {
		$repository = new FormRepository();

		return $repository->create( $name, FormSchema::empty( $name ), 'published' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_POST, $_REQUEST['_wpnonce'] );

		parent::tearDown();
	}
}
