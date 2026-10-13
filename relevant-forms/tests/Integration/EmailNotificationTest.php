<?php
/**
 * Email notification integration tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Events\EventRecorder;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Notifications\EmailNotification;
use RelevantForms\Tests\Integration\Support\CapturesMail;

/**
 * Email notification integration tests.
 *
 * @group integration
 */
final class EmailNotificationTest extends TestCase {

	use CapturesMail;

	/**
	 * Test recipients fall back to the site admin email.
	 */
	public function testFallsBackToAdminEmail(): void {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'No Recipients' )->withFields(
			[
				[
					'id'    => 'f_message',
					'type'  => 'textarea',
					'label' => 'Message',
				],
			]
		);

		$id   = $repository->create( 'No Recipients', $schema, 'published' );
		$form = $repository->find( $id );

		update_option( 'admin_email', 'admin@example.com' );

		$this->captureMail();

		$result = ( new EmailNotification() )->send( $form, [ 'f_message' => 'Hello' ], null );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->sent_mail );

		// The configured administration address is the first recipient.
		$this->assertSame( 'admin@example.com', $this->sent_mail[0]['to'][0] );
		$this->assertStringContainsString( 'Hello', $this->sent_mail[0]['message'] );
	}

	/**
	 * Without form recipients every site administrator gets the email.
	 */
	public function testFallsBackToAllSiteAdministrators(): void {
		$repository = new FormRepository();

		$id   = $repository->create( 'Admins', FormSchema::empty( 'Admins' ), 'published' );
		$form = $repository->find( $id );

		self::factory()->user->create(
			[
				'role'       => 'administrator',
				'user_email' => 'first@example.com',
			]
		);
		self::factory()->user->create(
			[
				'role'       => 'administrator',
				'user_email' => 'second@example.com',
			]
		);
		self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_email' => 'editor@example.com',
			]
		);

		$this->captureMail();

		$this->assertTrue( ( new EmailNotification() )->send( $form, [], null ) );

		$to = $this->sent_mail[0]['to'];

		$this->assertContains( 'first@example.com', $to );
		$this->assertContains( 'second@example.com', $to );
		$this->assertNotContains( 'editor@example.com', $to );
	}

	/**
	 * Test failures are recorded as notification_failed events.
	 */
	public function testFailureIsRecorded(): void {
		$repository = new FormRepository();
		$id         = $repository->create( 'Failing', FormSchema::empty( 'Failing' ), 'published' );
		$form       = $repository->find( $id );

		$this->failMail();

		$result = ( new EmailNotification() )->send( $form, [], null );

		$this->assertFalse( $result );

		$events = array_column( EventRecorder::forEntry( 0 ), 'event' );

		// Events were recorded without an entry id; query them directly.
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT event FROM ' . $wpdb->prefix . 'relevant_events ORDER BY id DESC LIMIT 5' );

		$this->assertContains( 'notification_failed', array_column( (array) $rows, 'event' ) );

		// A silent mailer (no exception, no wp_mail_failed) still says why the
		// send is marked failed, so the event is not a dead end.
		$this->assertSame(
			'The mailer reported a failure without an error message.',
			$this->latestEventContext( 'notification_failed' )['error'] ?? null
		);
	}

	/**
	 * A mailer that throws is recorded with the reason it gave.
	 */
	public function testMailerExceptionIsRecordedWithItsReason(): void {
		$repository = new FormRepository();
		$id         = $repository->create( 'Throwing', FormSchema::empty( 'Throwing' ), 'published' );
		$form       = $repository->find( $id );

		/*
		 * Point PHPMailer at a closed port. A refused connection is the only
		 * way to reach core's catch block, and that block is where the reason
		 * behind a failed wp_mail() actually lives.
		 */
		$refuse = static function ( $phpmailer ): void {
			$phpmailer->isSMTP();
			$phpmailer->Host    = '127.0.0.1';
			$phpmailer->Port    = 9;
			$phpmailer->Timeout = 2;
		};

		add_action( 'phpmailer_init', $refuse );

		try {
			$result = ( new EmailNotification() )->send( $form, [], null );
		} finally {
			remove_action( 'phpmailer_init', $refuse );
		}

		$this->assertFalse( $result );

		$reason = (string) ( $this->latestEventContext( 'notification_failed' )['error'] ?? '' );

		$this->assertNotSame( '', $reason );

		// The mailer's own words, not the silent-failure placeholder.
		$this->assertStringNotContainsString( 'without an error message', $reason );
	}

	/**
	 * Context of the most recent event of a kind.
	 *
	 * @param string $event Event name.
	 * @return array<string, mixed>
	 */
	private function latestEventContext( string $event ): array {
		global $wpdb;

		$context = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT context FROM ' . $wpdb->prefix . 'relevant_events WHERE event = %s ORDER BY id DESC LIMIT 1',
				$event
			)
		);

		return (array) json_decode( (string) $context, true );
	}

	/**
	 * A per-form sender is used as the From header.
	 */
	public function testFormSenderBecomesTheFromHeader(): void {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'With Sender' )->withSender( 'Acme Forms', 'no-reply@acme.example' );
		$id     = $repository->create( 'With Sender', $schema, 'published' );
		$form   = $repository->find( $id );

		update_option( 'admin_email', 'admin@example.com' );

		$this->captureMail();

		$this->assertTrue( ( new EmailNotification() )->send( $form, [], null ) );
		$this->assertContains( 'From: Acme Forms <no-reply@acme.example>', (array) $this->sent_mail[0]['headers'] );
	}

	/**
	 * An incomplete sender adds no From header (WordPress default applies).
	 */
	public function testIncompleteSenderAddsNoFromHeader(): void {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Name Only' )->withSender( 'Acme Forms', 'not-an-email' );
		$id     = $repository->create( 'Name Only', $schema, 'published' );
		$form   = $repository->find( $id );

		update_option( 'admin_email', 'admin@example.com' );

		$this->captureMail();

		$this->assertTrue( ( new EmailNotification() )->send( $form, [], null ) );

		foreach ( (array) $this->sent_mail[0]['headers'] as $header ) {
			$this->assertStringNotContainsString( 'From:', (string) $header );
		}
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		parent::tearDown();
	}
}
