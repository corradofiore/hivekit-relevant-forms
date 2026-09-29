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

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class TestEmailFlowTest extends TestCase {

	/**
	 * Outgoing mail captured by the fake transport.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

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
		$this->assertCount( 1, $this->sent );
		$this->assertContains( 'admin@example.com', $this->sent[0]['to'] );

		// The form's sender identity rides along.
		$this->assertContains( 'From: Acme Forms <no-reply@acme.example>', (array) $this->sent[0]['headers'] );
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
		$this->assertSame( [ 'deliverability@test.example', 'second@test.example' ], $this->sent[0]['to'] );

		// No sender configured: only the content type header.
		$this->assertSame( [ 'Content-Type: text/plain; charset=UTF-8' ], $this->sent[0]['headers'] );
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
		$this->assertCount( 0, $this->sent );
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
	 * Capture outgoing mail through the fake transport.
	 */
	private function captureMail(): void {
		add_filter(
			'pre_wp_mail',
			function ( ?bool $shortCircuit, array $args ): bool {
				$this->sent[] = $args;

				return true;
			},
			10,
			2
		);
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

		/*
		 * wp_send_json() only routes through wp_die() in a real admin-ajax
		 * request (wp_doing_ajax()); otherwise it exits directly. Simulate the
		 * AJAX context and intercept its die handler — the test framework
		 * overrides only the default wp_die() handler, not the AJAX one.
		 */
		$doing_ajax = static function (): bool {
			return true;
		};

		$ajax_die = static function (): callable {
			return static function (): void {
				throw new \WPDieException();
			};
		};

		add_filter( 'wp_doing_ajax', $doing_ajax );
		add_filter( 'wp_die_ajax_handler', $ajax_die );

		$terminated = false;

		ob_start();

		try {
			TestEmailController::send();
		} catch ( \WPDieException ) {
			$terminated = true;
		} finally {
			$body = json_decode( (string) ob_get_clean(), true );

			remove_filter( 'wp_doing_ajax', $doing_ajax );
			remove_filter( 'wp_die_ajax_handler', $ajax_die );
		}

		$this->assertTrue( $terminated, 'wp_send_json should terminate the request.' );

		return is_array( $body ) ? $body : [];
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_POST, $_REQUEST['_wpnonce'] );
		remove_all_filters( 'pre_wp_mail' );

		parent::tearDown();
	}
}
