<?php
/**
 * Outbound mail transport detection.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Notifications;

/**
 * Whether anything on this site owns the outbound mail transport.
 *
 * WordPress hands mail to PHP mail() unless a mailer takes over, and mail()
 * is blocked or spam-foldered by most shared hosts and by every large mailbox
 * provider. A submission notification that never arrives looks exactly like a
 * visitor who never wrote, so the site is told before that happens.
 *
 * Detection is hook-based, because it has to be certain: a mailer must either
 * configure PHPMailer — SMTP and API relays both hook `phpmailer_init` — or
 * answer wp_mail() itself through `pre_wp_mail`. A site with neither hook is
 * on the mail() fallback.
 */
final class MailTransport {

	/**
	 * Whether a mailer appears to own the transport.
	 */
	public static function isConfigured(): bool {
		if ( has_action( 'phpmailer_init' ) ) {
			return true;
		}

		return (bool) has_filter( 'pre_wp_mail' );
	}

	/**
	 * What to tell an operator when nothing owns the transport.
	 *
	 * Shared by the admin notice and the Diagnostics page so the warning
	 * reads the same wherever it is met.
	 */
	public static function advice(): string {
		return __( 'WordPress will hand notification emails to PHP mail(), which most hosts block or spam-folder. Install and configure an SMTP plugin (or a transactional mail relay for this site), then send a test email to confirm delivery.', 'relevant-forms' );
	}
}
