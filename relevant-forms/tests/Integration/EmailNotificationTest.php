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

/**
 * Email notification integration tests.
 *
 * @group integration
 */
final class EmailNotificationTest extends TestCase {

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

		$sent = [];

		add_filter(
			'pre_wp_mail',
			static function ( ?bool $shortCircuit, array $args ) use ( &$sent ): bool {
				$sent[] = $args;
				return true;
			},
			10,
			2
		);

		$result = ( new EmailNotification() )->send( $form, [ 'f_message' => 'Hello' ], null );

		$this->assertTrue( $result );
		$this->assertCount( 1, $sent );
		$this->assertSame( [ 'admin@example.com' ], $sent[0]['to'] );
		$this->assertStringContainsString( 'Hello', $sent[0]['message'] );
	}

	/**
	 * Test failures are recorded as notification_failed events.
	 */
	public function testFailureIsRecorded(): void {
		$repository = new FormRepository();
		$id         = $repository->create( 'Failing', FormSchema::empty( 'Failing' ), 'published' );
		$form       = $repository->find( $id );

		add_filter( 'pre_wp_mail', static fn (): bool => false );

		$result = ( new EmailNotification() )->send( $form, [], null );

		$this->assertFalse( $result );

		$events = array_column( EventRecorder::forEntry( 0 ), 'event' );

		// Events were recorded without an entry id; query them directly.
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT event FROM ' . $wpdb->prefix . 'relevant_events ORDER BY id DESC LIMIT 5' );

		$this->assertContains( 'notification_failed', array_column( (array) $rows, 'event' ) );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		parent::tearDown();
	}
}
