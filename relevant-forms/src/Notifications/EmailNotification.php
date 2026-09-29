<?php
/**
 * Email notification action (doc §20, §24).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Notifications;

use RelevantForms\Ai\Classification;
use RelevantForms\Events\EventRecorder;
use RelevantForms\Forms\Form;
use RelevantForms\Support\Logger;
use RelevantForms\Support\SiteAdmins;

/**
 * Sends plain-text submission emails via wp_mail and records the outcome.
 */
final class EmailNotification {

	/**
	 * Send the notification email for a submission.
	 *
	 * Failures never destroy the submission: they are logged and recorded as
	 * a notification_failed event (doc §57).
	 *
	 * @param Form                 $form           Form the submission belongs to.
	 * @param array<string, mixed> $values         Sanitized field values.
	 * @param int|null             $entryId        Related entry ID.
	 * @param Classification|null  $classification Validated AI classification (doc §16).
	 */
	public function send( Form $form, array $values, ?int $entryId = null, ?Classification $classification = null ): bool {
		$recipients = $form->schema->notificationEmails();

		if ( $recipients === [] ) {
			$recipients = SiteAdmins::emails();
		}

		$recipients = array_values( array_filter( $recipients, 'is_email' ) );

		if ( $recipients === [] ) {
			Logger::warning( 'No valid notification recipients', [ 'form_id' => $form->id ] );

			return false;
		}

		$siteName = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$summary  = self::summarize( $classification );

		// The AI summary leads the subject line so the inbox shows what it is.
		$subject = '' !== $summary
			? sprintf( '[%s] %s — %s', $siteName, $form->schema->name(), $summary )
			: sprintf( '[%s] %s', $siteName, $form->schema->name() );
		$body    = $this->buildBody( $form, $values );
		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		// Per-form sender identity: name and address are used together or
		// not at all (WordPress then applies its default From).
		$from = SenderIdentity::fromHeader( $form );

		if ( '' !== $from ) {
			$headers[] = $from;
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
	 * One-line subject summary from a classification (5-7 words expected).
	 *
	 * Whitespace is collapsed so model output can never inject mail headers.
	 *
	 * @param Classification|null $classification Validated classification.
	 */
	private static function summarize( ?Classification $classification ): string {
		if ( null === $classification ) {
			return '';
		}

		return trim( (string) preg_replace( '/\s+/u', ' ', $classification->summary ) );
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
			__( 'New submission for form: %s', 'relevant-forms' ),
			$form->schema->name()
		);
		$lines[] = sprintf(
			/* translators: %s: date and time */
			__( 'Received: %s', 'relevant-forms' ),
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
