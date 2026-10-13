<?php
/**
 * Admin notices for the plugin's own screens.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin;

use RelevantForms\Notifications\MailTransport;

/**
 * Warnings that belong in front of an operator, not in a log.
 */
final class Notices {

	/**
	 * Register the notice hooks.
	 */
	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'mailTransport' ] );
	}

	/**
	 * Warn when nothing owns the outbound mail transport.
	 *
	 * Shown on this plugin's screens only: the site's mail setup concerns the
	 * whole of wp-admin, but a warning repeated on every admin screen would be
	 * tuned out, and the notifications it protects are configured here.
	 */
	public static function mailTransport(): void {
		if ( MailTransport::isConfigured() ) {
			return;
		}

		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! self::onPluginScreen() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Relevant Forms: submission emails may never arrive.', 'relevant-forms' ),
			esc_html( MailTransport::advice() ),
			esc_url( admin_url( 'admin.php?page=relevant-diagnostics' ) ),
			esc_html__( 'Open Diagnostics', 'relevant-forms' )
		);
	}

	/**
	 * Whether the current screen is one of the plugin's admin pages.
	 */
	private static function onPluginScreen(): bool {
		$screen = get_current_screen();

		if ( null === $screen ) {
			return false;
		}

		return false !== strpos( (string) $screen->id, 'relevant-' );
	}
}
