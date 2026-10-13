<?php
/**
 * Mail transport notice integration tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Notices;

/**
 * The missing-SMTP warning shows on the plugin's screens, and only there.
 *
 * @group integration
 */
final class MailTransportNoticeTest extends TestCase {

	/**
	 * Warning shown on a plugin screen when nothing owns the transport.
	 */
	public function testWarningIsShownOnAPluginScreen(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->onScreen( 'toplevel_page_relevant-forms' );

		$this->expectOutputRegex( '/submission emails may never arrive/' );

		Notices::mailTransport();
	}

	/**
	 * A configured mailer silences the warning.
	 */
	public function testWarningIsSilentWhenAMailerIsConfigured(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->onScreen( 'toplevel_page_relevant-forms' );

		$configure = static function ( $phpmailer ): void {
			$phpmailer->isSMTP();
		};

		add_action( 'phpmailer_init', $configure );

		try {
			$this->expectOutputString( '' );

			Notices::mailTransport();
		} finally {
			remove_action( 'phpmailer_init', $configure );
		}
	}

	/**
	 * Other admin screens are left alone.
	 */
	public function testWarningIsSilentElsewhereInTheAdmin(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->onScreen( 'dashboard' );

		$this->expectOutputString( '' );

		Notices::mailTransport();
	}

	/**
	 * Without the capability the notifying user is not the one who acts.
	 */
	public function testWarningIsSilentForUsersWhoCannotManageTheSite(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->onScreen( 'toplevel_page_relevant-forms' );

		$this->expectOutputString( '' );

		Notices::mailTransport();
	}

	/**
	 * Pretend the request is a given admin screen.
	 *
	 * @param string $id Screen id.
	 */
	private function onScreen( string $id ): void {
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		set_current_screen( $id );
	}
}
