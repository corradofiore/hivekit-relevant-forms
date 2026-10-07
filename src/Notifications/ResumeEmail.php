<?php
/**
 * Save and Resume link email.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Notifications;

use RelevantForms\Events\EventRecorder;
use RelevantForms\Forms\Form;
use RelevantForms\Support\Logger;

/**
 * Sends the resume link email for a partial (Save and Resume) entry. The
 * copy comes from the per-form overrides when set, otherwise from the
 * shipped defaults. Supported placeholders: {resume_link}, {form_name},
 * {expiry_days} and {site_name}.
 */
final class ResumeEmail {

	/**
	 * Shipped default subject.
	 */
	public const DEFAULT_SUBJECT = 'Your saved progress on {form_name}';

	/**
	 * Shipped default body.
	 */
	public const DEFAULT_BODY = "Hi,\n\nYou asked to save your progress on {form_name} so you can finish it later.\n\nPick up where you left off:\n{resume_link}\n\nThis link works for {expiry_days} days.\n\nIf you did not ask for this, you can ignore this email.\n\n{site_name}";

	/**
	 * Send the resume link email.
	 *
	 * Failures never destroy the saved progress: they are logged and
	 * recorded as a resume_link_failed event.
	 *
	 * @param Form     $form      Form entity.
	 * @param string   $email     Recipient address.
	 * @param string   $token     Raw resume token (kept for the log event only).
	 * @param string   $resumeUrl Full resume URL (page URL plus query args).
	 * @param string   $expiresAt UTC expiry datetime.
	 * @param int|null $entryId   Related partial entry ID.
	 */
	public function send( Form $form, string $email, string $token, string $resumeUrl, string $expiresAt, ?int $entryId = null ): bool {
		$config  = $form->schema->saveResume();
		$subject = self::render(
			'' !== trim( $config['email_subject'] ) ? $config['email_subject'] : self::DEFAULT_SUBJECT,
			$form,
			$resumeUrl,
			(int) $config['expiry_days']
		);
		$body    = self::render(
			'' !== trim( $config['email_body'] ) ? $config['email_body'] : self::DEFAULT_BODY,
			$form,
			$resumeUrl,
			(int) $config['expiry_days']
		);

		// A custom body without the placeholder still needs the link.
		if ( ! str_contains( $body, $resumeUrl ) ) {
			$body .= "\n\n" . $resumeUrl;
		}

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		$from = SenderIdentity::fromHeader( $form );

		if ( '' !== $from ) {
			$headers[] = $from;
		}

		/**
		 * Filters the resume link email before it is sent (docs/hooks.md).
		 *
		 * @param array{to: string, subject: string, body: string, headers: array<int, string>, resume_url: string, expires_at: string} $email Email payload.
		 * @param Form                                                                                                              $form  Form the saved progress belongs to.
		 */
		$payload = (array) apply_filters(
			'relevant_forms_save_resume_email',
			[
				'to'         => $email,
				'subject'    => $subject,
				'body'       => $body,
				'headers'    => $headers,
				'resume_url' => $resumeUrl,
				'expires_at' => $expiresAt,
			],
			$form
		);

		try {
			$sent = wp_mail(
				(string) ( $payload['to'] ?? $email ),
				(string) ( $payload['subject'] ?? $subject ),
				(string) ( $payload['body'] ?? $body ),
				(array) ( $payload['headers'] ?? $headers )
			);
		} catch ( \Throwable $exception ) {
			$sent = false;

			Logger::error(
				'Resume email threw',
				[
					'form_id'   => $form->id,
					'exception' => $exception->getMessage(),
				]
			);
		}

		EventRecorder::record(
			$entryId,
			$sent ? 'resume_link_sent' : 'resume_link_failed',
			[
				'channel'   => 'email',
				'form_id'   => $form->id,
				'recipient' => (string) ( $payload['to'] ?? $email ),
				'subject'   => (string) ( $payload['subject'] ?? $subject ),
			]
		);

		return (bool) $sent;
	}

	/**
	 * Substitute the supported placeholders.
	 *
	 * @param string $template   Subject or body template.
	 * @param Form   $form       Form entity.
	 * @param string $resumeUrl  Full resume URL.
	 * @param int    $expiryDays Validity window in days.
	 */
	private static function render( string $template, Form $form, string $resumeUrl, int $expiryDays ): string {
		$siteName = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		return strtr(
			$template,
			[
				'{resume_link}' => $resumeUrl,
				'{form_name}'   => $form->schema->name(),
				'{expiry_days}' => (string) $expiryDays,
				'{site_name}'   => $siteName,
			]
		);
	}
}
