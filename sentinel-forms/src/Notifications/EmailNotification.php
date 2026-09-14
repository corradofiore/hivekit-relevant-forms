<?php
/**
 * Email notification action (doc §20, §24).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Notifications;

use SentinelForms\Events\EventRecorder;
use SentinelForms\Forms\Form;
use SentinelForms\Support\Logger;

/**
 * Sends plain-text submission emails via wp_mail and records the outcome.
 */
final class EmailNotification {

	/**
	 * Settings option key.
	 */
	private const OPTION = 'sentinel_forms_settings';

	/**
	 * Send the notification email for a submission.
	 *
	 * Failures never destroy the submission: they are logged and recorded as
	 * a notification_failed event (doc §57).
	 *
	 * @param Form                 $form    Form the submission belongs to.
	 * @param array<string, mixed> $values  Sanitized field values.
	 * @param int|null             $entryId Related entry ID.
	 */
	public function send( Form $form, array $values, ?int $entryId = null ): bool {
		$recipients = $form->schema->notificationEmails();

		if ( $recipients === [] ) {
			$adminEmail = (string) get_option( 'admin_email' );
			if ( '' !== $adminEmail ) {
				$recipients = [ $adminEmail ];
			}
		}

		$recipients = array_values( array_filter( $recipients, 'is_email' ) );

		if ( $recipients === [] ) {
			Logger::warning( 'No valid notification recipients', [ 'form_id' => $form->id ] );

			return false;
		}

		$settings = (array) get_option( self::OPTION, [] );
		$subject  = sprintf(
			'[%s] %s',
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			$form->schema->name()
		);
		$body     = $this->buildBody( $form, $values );
		$headers  = [ 'Content-Type: text/plain; charset=UTF-8' ];

		$senderName  = (string) ( $settings['notification_sender_name'] ?? '' );
		$senderEmail = (string) ( $settings['notification_sender_email'] ?? '' );

		if ( '' !== $senderName && is_email( $senderEmail ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $senderName, $senderEmail );
		}

		try {
			$sent = wp_mail( $recipients, $subject, $body, $headers );
		} catch ( \Throwable $exception ) {
			$sent = false;
			Logger::error(
				'Email notification threw',
				[
					'form_id'   => $form->id,
					'exception' => $exception->getMessage(),
				]
			);
		}

		$event   = $sent ? 'notification_sent' : 'notification_failed';
		$context = [
			'channel'    => 'email',
			'form_id'    => $form->id,
			'recipients' => $recipients,
			'subject'    => $subject,
		];

		EventRecorder::record( $entryId, $event, $context );

		return (bool) $sent;
	}

	/**
	 * Build a plain-text body from the form schema and values.
	 *
	 * @param Form                 $form   Form.
	 * @param array<string, mixed> $values Sanitized field values.
	 */
	private function buildBody( Form $form, array $values ): string {
		$lines   = [];
		$lines[] = sprintf(
			/* translators: %s: form name */
			__( 'New submission for form: %s', 'sentinel-forms' ),
			$form->schema->name()
		);
		$lines[] = sprintf(
			/* translators: %s: date and time */
			__( 'Received: %s', 'sentinel-forms' ),
			current_time( 'mysql' )
		);
		$lines[] = str_repeat( '-', 32 );

		foreach ( $form->schema->fields() as $definition ) {
			$fieldId = (string) ( $definition['id'] ?? '' );

			if ( '' === $fieldId || ! array_key_exists( $fieldId, $values ) ) {
				continue;
			}

			$value = self::formatValue( $values[ $fieldId ] );

			if ( '' === $value ) {
				continue;
			}

			$lines[] = (string) ( $definition['label'] ?? $fieldId ) . ': ' . $value;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Format a stored value for plain text output.
	 */
	private static function formatValue( mixed $value ): string {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}
}
