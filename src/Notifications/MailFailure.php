<?php
/**
 * Why the last wp_mail() call failed.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Notifications;

/**
 * Captures the reason behind a failed wp_mail() call.
 *
 * The core function answers with a bare false, so the reason only exists in
 * the `wp_mail_failed` action, which fires when PHPMailer throws. The listener
 * is scoped to a single send: mail sent by another plugin on the same request
 * must not be attributed to this notification.
 */
final class MailFailure {

	/**
	 * Longest reason kept; PHPMailer messages carry provider detail.
	 */
	private const MAX_LENGTH = 300;

	/**
	 * Reason reported so far, empty when none was.
	 *
	 * @var string
	 */
	private string $message = '';

	/**
	 * Registered listener.
	 *
	 * @var \Closure
	 */
	private \Closure $listener;

	/**
	 * Start listening for the next send.
	 */
	public function __construct() {
		$this->listener = function ( $error ): void {
			if ( $error instanceof \WP_Error ) {
				$this->record( (string) $error->get_error_message() );
			}
		};

		add_action( 'wp_mail_failed', $this->listener );
	}

	/**
	 * Remember the first reason reported for this send.
	 *
	 * @param string $message Error message.
	 */
	public function record( string $message ): void {
		if ( '' !== $this->message ) {
			return;
		}

		$this->message = trim( (string) preg_replace( '/\s+/u', ' ', $message ) );
	}

	/**
	 * The recorded reason, or a fallback when the failure was silent.
	 *
	 * A mailer can fail without throwing: a blocked PHP mail(), a relay that
	 * answers false, a short-circuiting plugin. "Failed" with no cause is not
	 * something an operator can act on, so it is stated plainly instead.
	 */
	public function reason(): string {
		if ( '' === $this->message ) {
			return __( 'The mailer reported a failure without an error message.', 'relevant-forms' );
		}

		return mb_substr( $this->message, 0, self::MAX_LENGTH );
	}

	/**
	 * Stop listening.
	 */
	public function stop(): void {
		remove_action( 'wp_mail_failed', $this->listener );
	}
}
