<?php
/**
 * Test-email sender for the form editor (admin-ajax).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Controllers;

use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Notifications\SenderIdentity;
use RelevantForms\Support\Logger;
use RelevantForms\Support\SiteAdmins;
use RelevantForms\Support\TextList;

/**
 * Sends one test notification so sender identity and deliverability can
 * be checked from the form editor: to the site administrators, or to any
 * addresses typed in (e.g. a deliverability testing service).
 */
final class TestEmailController {

	/**
	 * AJAX action (also the nonce action).
	 */
	public const ACTION = 'relevant_forms_send_test_email';

	/**
	 * Register the AJAX handler.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'send' ] );
	}

	/**
	 * AJAX: send the test email.
	 */
	public static function send(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to send test emails.', 'relevant-forms' ) ], 403 );
		}

		check_ajax_referer( self::ACTION );

		$formId = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$form   = $formId > 0 ? ( new FormRepository() )->find( $formId ) : null;

		if ( ! $form instanceof Form ) {
			wp_send_json_error(
				[
					'code'    => 'not_found',
					'message' => __( 'The form could not be found.', 'relevant-forms' ),
				],
				404
			);
		}

		$submitted  = trim( sanitize_textarea_field( wp_unslash( (string) ( $_POST['recipients'] ?? '' ) ) ) );
		$recipients = self::parseRecipients( $submitted );

		if ( '' !== $submitted && [] === $recipients ) {
			wp_send_json_error(
				[
					'code'    => 'invalid_recipients',
					'message' => __( 'Use valid email addresses, one per line.', 'relevant-forms' ),
				],
				400
			);
		}

		if ( [] === $recipients ) {
			// No explicit addresses: the site administrators are the target.
			$recipients = SiteAdmins::emails();
		}

		if ( [] === $recipients ) {
			wp_send_json_error(
				[
					'code'    => 'no_recipients',
					'message' => __( 'Add at least one email address, or give an administrator account a valid email.', 'relevant-forms' ),
				],
				400
			);
		}

		$siteName = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: 1: site name, 2: form name. */
			__( '[%1$s] Test email — %2$s', 'relevant-forms' ),
			$siteName,
			$form->schema->name()
		);

		$body = sprintf(
			/* translators: 1: form name, 2: user login, 3: date and time. */
			__(
				"This is a test email for the \"%1\$s\" form, sent from the form editor.\n\nIf you received it, email delivery works from this site. Submission notifications use the same sender settings.\n\nRequested by %2\$s on %3\$s.",
				'relevant-forms'
			),
			$form->schema->name(),
			(string) wp_get_current_user()->user_login,
			(string) current_time( 'mysql' )
		);

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
		$from    = SenderIdentity::fromHeader( $form );

		if ( '' !== $from ) {
			$headers[] = $from;
		}

		try {
			$sent = wp_mail( $recipients, $subject, $body, $headers );
		} catch ( \Throwable $exception ) {
			$sent = false;
			Logger::error(
				'Test email threw',
				[
					'form_id'   => $form->id,
					'exception' => $exception->getMessage(),
				]
			);
		}

		if ( ! $sent ) {
			wp_send_json_error(
				[
					'code'    => 'send_failed',
					'message' => __( 'WordPress could not send the test email. Check the site\'s mail configuration.', 'relevant-forms' ),
				],
				502
			);
		}

		wp_send_json_success( [ 'recipients' => $recipients ] );
	}

	/**
	 * Split textarea input into unique valid addresses.
	 *
	 * @param string $raw Raw textarea value.
	 * @return array<int, string>
	 */
	private static function parseRecipients( string $raw ): array {
		$recipients = [];

		foreach ( TextList::lines( str_replace( ',', "\n", $raw ) ) as $email ) {
			if ( is_email( $email ) ) {
				$recipients[] = $email;
			}
		}

		return array_values( array_unique( $recipients ) );
	}
}
