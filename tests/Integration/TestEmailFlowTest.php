<?php
/**
 * Form editor test-email AJAX integration tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\TestEmailController;
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

		add_filter( 'pre_wp_mail', static fn (): bool => false );

		$body = $this->dispatch( $form->id, 'deliverability@test.example' );

		$this->assertFalse( $body['success'] );
		$this->assertSame( 'send_failed', $body['data']['code'] );
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
