<?php
/**
 * Form editor test-email AJAX integration tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\TestEmailController;
use RelevantForms\Admin\Pages\FormEditPage;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Tests\Integration\Support\AjaxRequests;
use RelevantForms\Tests\Integration\Support\CapturesMail;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class TestEmailFlowTest extends TestCase {

	use CapturesMail;

	/**
	 * Empty recipients: the site administrators get the test email.
	 */
	public function testSendsToAdministratorsWhenRecipientsAreEmpty(): void {
		wp_set_current_user(
			self::factory()->user->create(
				[
					'role'       => 'administrator',
					'user_email' => 'admin@example.com',
				]
			)
		);

		update_option( 'admin_email', 'admin@example.com' );

		$form = $this->makeForm( true );

		$this->captureMail();

		$body = $this->dispatch( $form->id, '' );

		$this->assertTrue( $body['success'] );

		// The configured administration address comes first, then the
		// administrator accounts.
		$this->assertSame( 'admin@example.com', $body['data']['recipients'][0] );
		$this->assertCount( 1, $this->sent_mail );
		$this->assertContains( 'admin@example.com', $this->sent_mail[0]['to'] );

		// The form's sender identity rides along.
		$this->assertContains( 'From: Acme Forms <no-reply@acme.example>', (array) $this->sent_mail[0]['headers'] );
	}

	/**
	 * Explicit addresses win over the administrators fallback.
	 */
	public function testSendsToExplicitAddresses(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$form = $this->makeForm();

		$this->captureMail();

		$body = $this->dispatch( $form->id, "deliverability@test.example\nsecond@test.example" );

		$this->assertTrue( $body['success'] );
		$this->assertSame( [ 'deliverability@test.example', 'second@test.example' ], $body['data']['recipients'] );
		$this->assertSame( [ 'deliverability@test.example', 'second@test.example' ], $this->sent_mail[0]['to'] );

		// No sender configured: only the content type header.
		$this->assertSame( [ 'Content-Type: text/plain; charset=UTF-8' ], $this->sent_mail[0]['headers'] );
	}

	/**
	 * Invalid addresses are rejected without sending anything.
	 */
	public function testInvalidAddressesAreRejected(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$form = $this->makeForm();

		$this->captureMail();

		$body = $this->dispatch( $form->id, 'not-an-email' );

		$this->assertFalse( $body['success'] );
		$this->assertSame( 'invalid_recipients', $body['data']['code'] );
		$this->assertCount( 0, $this->sent_mail );
	}

	/**
	 * A mail failure surfaces as an error envelope.
	 */
	public function testMailFailureIsReported(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$form = $this->makeForm();

		$this->failMail();

		$body = $this->dispatch( $form->id, 'deliverability@test.example' );

		$this->assertFalse( $body['success'] );
		$this->assertSame( 'send_failed', $body['data']['code'] );
	}

	/**
	 * The test-email controls render only once the form exists: the backend
	 * renders the test message from the saved form.
	 */
	public function testTheTestEmailControlsAreHiddenUntilTheFormExists(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$form = $this->makeForm();

		$_GET['form_id'] = $form->id;

		ob_start();
		FormEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="rf-send-test-email"', $html );
		$this->assertStringContainsString( 'id="relevant-test-email-to"', $html );

		// A new form has not been created yet: no test-email row at all.
		unset( $_GET['form_id'] );

		ob_start();
		FormEditPage::render();
		$new_html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h1>Add New Form</h1>', $new_html );
		$this->assertStringNotContainsString( 'Send test email', $new_html );
		$this->assertStringNotContainsString( 'rf-send-test-email', $new_html );
		$this->assertStringNotContainsString( 'relevant-test-email-to', $new_html );
	}

	/**
	 * Build a published form (optionally with a sender identity).
	 *
	 * @param bool $withSender Whether to configure the sender identity.
	 */
	private function makeForm( bool $withSender = false ): Form {
		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Contact' );

		if ( $withSender ) {
			$schema = $schema->withSender( 'Acme Forms', 'no-reply@acme.example' );
		}

		$id = $repository->create( 'Contact', $schema, 'published' );

		return $repository->find( $id );
	}

	/**
	 * POST to the endpoint as an AJAX request.
	 *
	 * @param int    $formId     Form ID.
	 * @param string $recipients Raw recipients field.
	 * @return array<string, mixed> Decoded response body.
	 */
	private function dispatch( int $formId, string $recipients ): array {
		$_POST                = [
			'form_id'    => $formId,
			'recipients' => $recipients,
		];
		$_REQUEST['_wpnonce'] = wp_create_nonce( TestEmailController::ACTION );

		return AjaxRequests::dispatch( [ TestEmailController::class, 'send' ] );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_POST, $_REQUEST['_wpnonce'] );
		$this->stopCapturingMail();

		parent::tearDown();
	}
}
