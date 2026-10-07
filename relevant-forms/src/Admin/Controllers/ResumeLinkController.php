<?php
/**
 * Operator action: email a fresh resume link for a partial entry.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Controllers;

use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Entries\Resume\PartialSaveService;
use RelevantForms\Entries\Resume\ResumeTokens;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Notifications\ResumeEmail;
use RelevantForms\Support\Logger;

/**
 * Regenerates the resume token of a partial entry and emails the link to the
 * address on file — support tooling for Save and Resume.
 */
final class ResumeLinkController {

	/**
	 * Register the admin-post action.
	 */
	public static function register(): void {
		add_action( 'admin_post_relevant_forms_resend_resume', [ self::class, 'resend' ] );
	}

	/**
	 * Handle the resend request.
	 */
	public static function resend(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		$entryId = isset( $_POST['entry_id'] ) ? absint( wp_unslash( $_POST['entry_id'] ) ) : 0;

		check_admin_referer( 'relevant_resume_resend_' . $entryId );

		$repository = new EntryRepository();
		$entry      = $repository->find( $entryId );

		if ( null === $entry || Entry::STATUS_PARTIAL !== $entry->status || '' === (string) $entry->resumeEmail ) {
			self::redirect( $entryId, 'not_available' );
		}

		$form = ( new FormRepository() )->find( $entry->formId );

		if ( null === $form ) {
			self::redirect( $entryId, 'not_available' );
		}

		$config    = $form->schema->saveResume();
		$token     = ResumeTokens::generate();
		$expiresAt = ResumeTokens::expiry( (int) $config['expiry_days'] );

		$repository->updatePartial(
			new Entry(
				$entry->id,
				$entry->formId,
				$entry->formVersionId,
				$entry->status,
				$entry->sourceUrl,
				$entry->referrer,
				$entry->userAgentHash,
				$entry->ipHash,
				$entry->createdAt,
				current_time( 'mysql', true ),
				$entry->meta,
				ResumeTokens::hash( $token ),
				(string) $entry->resumeEmail,
				$expiresAt,
				$entry->resumePage,
				$entry->resumeEmailSentAt
			)
		);

		$resumeUrl = PartialSaveService::resumeUrl( $entry->sourceUrl, $token, $entry->formId );

		$sent = ( new ResumeEmail() )->send( $form, (string) $entry->resumeEmail, $token, $resumeUrl, $expiresAt, $entryId );

		if ( $sent ) {
			$repository->markResumeEmailSent( $entryId );
		}

		Logger::info(
			'Resume link re-sent from the entry screen',
			[
				'entry_id' => $entryId,
				'sent'     => $sent,
			]
		);

		self::redirect( $entryId, $sent ? 'sent' : 'failed' );
	}

	/**
	 * Redirect back to the entry screen with a status marker.
	 *
	 * @param int    $entryId Entry ID.
	 * @param string $notice  Status marker.
	 */
	private static function redirect( int $entryId, string $notice ): never {
		$target = add_query_arg(
			[
				'page'          => 'relevant-entry',
				'entry_id'      => $entryId,
				'resume_notice' => $notice,
			],
			admin_url( 'admin.php' )
		);

		// Test seam: allows integration tests to intercept the redirect.
		$target = (string) apply_filters( 'relevant_forms_redirect', $target, $entryId );

		wp_safe_redirect( $target );
		exit;
	}
}
