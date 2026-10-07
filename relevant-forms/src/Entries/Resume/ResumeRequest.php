<?php
/**
 * Resume link resolution for the current request.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Entries\Resume;

use RelevantForms\Entries\EntryRepository;
use RelevantForms\Forms\Form;

/**
 * Reads the `relevant_resume` query argument, validates the token against
 * the partial entries and returns the saved values for pre-filling. The
 * token itself is the credential: it is a 256-bit random value, only its
 * SHA-256 hash is stored, and it expires with the per-form window.
 */
final class ResumeRequest {

	/**
	 * Resolve the resume state for one form.
	 *
	 * Statuses: none (no token for this form), invalid (expired, unknown or
	 * mismatched token) and resolved (values available).
	 *
	 * @param Form $form Form being rendered.
	 * @return array{status: string, entry?: \RelevantForms\Entries\Entry, token?: string, values?: array<string, mixed>}
	 */
	public static function resolve( Form $form ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup; the token is the credential.
		$raw = isset( $_GET['relevant_resume'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['relevant_resume'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup; used only to skip unrelated forms.
		$targetForm = isset( $_GET['relevant_form'] ) ? absint( wp_unslash( $_GET['relevant_form'] ) ) : 0;

		if ( 0 !== $targetForm && $targetForm !== $form->id ) {
			// The link belongs to another form on this page.
			return [ 'status' => 'none' ];
		}

		$token = preg_replace( '/[^0-9a-f]/', '', strtolower( $raw ) );

		if ( ! is_string( $token ) || 64 !== strlen( $token ) ) {
			return '' === $raw ? [ 'status' => 'none' ] : [ 'status' => 'invalid' ];
		}

		$entry = ( new EntryRepository() )->findPartialByResumeHash( ResumeTokens::hash( $token ) );

		if ( null === $entry || $entry->formId !== $form->id || ResumeTokens::expired( (string) $entry->resumeExpiresAt ) ) {
			return [ 'status' => 'invalid' ];
		}

		return [
			'status' => 'resolved',
			'entry'  => $entry,
			'token'  => $token,
			'values' => $entry->meta,
		];
	}
}
