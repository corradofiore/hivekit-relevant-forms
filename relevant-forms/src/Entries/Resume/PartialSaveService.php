<?php
/**
 * Save and Resume partial-save orchestration.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Entries\Resume;

use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Forms\Fields\FieldTypeRegistry;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\Visibility\VisibilityRules;
use RelevantForms\Notifications\ResumeEmail;
use RelevantForms\Support\Logger;
use RelevantForms\Support\Privacy;

/**
 * Validates and stores a partial (in-progress) save, reusing the submission
 * sanitization pipeline without required-field validation, then emails the
 * resume link. File uploads are never part of a partial save.
 */
final class PartialSaveService {

	/**
	 * Save requests allowed per IP within the window.
	 */
	public const RATE_LIMIT_REQUESTS = 10;

	/**
	 * Resume emails allowed per address within the window.
	 */
	public const RATE_LIMIT_EMAILS = 3;

	/**
	 * Save the posted progress and send the resume link email.
	 *
	 * @param Form                 $form      Published form with Save and Resume enabled.
	 * @param array<string, mixed> $raw       Unslashed request payload (relevant_field_* keys).
	 * @param string               $email     Requested email address.
	 * @param int                  $page      Wizard page the user is on (1-based).
	 * @param string               $token     Raw token from an earlier save in this session (may be empty).
	 * @param string               $sourceUrl URL of the page the form lives on.
	 * @param string               $referrer  Request referer.
	 * @return array{status: string, message: string, token: string, expires_at: string, emailed: bool, entry_id: int}
	 */
	public function save( Form $form, array $raw, string $email, int $page, string $token, string $sourceUrl, string $referrer = '' ): array {
		if ( ! is_email( $email ) ) {
			return self::failure( 'invalid_email', __( 'Enter a valid email address.', 'relevant-forms' ) );
		}

		$ipHash = Privacy::ipHash();

		$requestLimit = (int) apply_filters( 'relevant_forms_save_resume_rate_limit', self::RATE_LIMIT_REQUESTS );

		if ( self::throttled( 'ip', $ipHash, $requestLimit, 10 * MINUTE_IN_SECONDS ) ) {
			return self::failure( 'rate_limited', __( 'Too many save requests — please try again in a few minutes.', 'relevant-forms' ) );
		}

		$repository = new EntryRepository();
		$existing   = self::findExisting( $repository, $form->id, $email, $token );

		// The same token keeps the already-emailed link valid; a new token is
		// issued when there is no matching partial or the request comes from
		// a different browser, replacing the previous link.
		$reuse     = null !== $existing && '' !== $token && $existing->resumeTokenHash === ResumeTokens::hash( $token );
		$rawToken  = $reuse ? $token : ResumeTokens::generate();
		$config    = $form->schema->saveResume();
		$expiresAt = ResumeTokens::expiry( (int) $config['expiry_days'] );
		$values    = self::sanitizeValues( $form, $raw );
		$now       = current_time( 'mysql', true );

		$entry = new Entry(
			null !== $existing ? $existing->id : null,
			$form->id,
			null !== $existing ? $existing->formVersionId : ( new FormRepository() )->createVersion( $form->id, $form->schema ),
			Entry::STATUS_PARTIAL,
			$sourceUrl,
			$referrer,
			Privacy::userAgentHash(),
			$ipHash,
			$now,
			$now,
			$values,
			ResumeTokens::hash( $rawToken ),
			$email,
			$expiresAt,
			max( 1, $page ),
			null !== $existing ? $existing->resumeEmailSentAt : null,
		);

		if ( null !== $existing ) {
			$repository->updatePartial( $entry );
			$entryId = (int) $existing->id;
		} else {
			$entryId = $repository->create( $entry );
		}

		$emailed    = false;
		$emailLimit = (int) apply_filters( 'relevant_forms_save_resume_email_rate_limit', self::RATE_LIMIT_EMAILS );

		if ( ! self::throttled( 'email', $email, $emailLimit, 15 * MINUTE_IN_SECONDS ) ) {
			$resumeUrl = self::resumeUrl( $sourceUrl, $rawToken, $form->id );

			$emailed = ( new ResumeEmail() )->send( $form, $email, $rawToken, $resumeUrl, $expiresAt, $entryId );

			if ( $emailed ) {
				$repository->markResumeEmailSent( $entryId );
			}
		}

		Logger::info(
			'Partial form progress saved',
			[
				'form_id'  => $form->id,
				'entry_id' => $entryId,
				'emailed'  => $emailed,
			]
		);

		return [
			'status'     => 'saved',
			'message'    => $emailed
				? sprintf(
					/* translators: %s: email address */
					__( 'Progress saved. We emailed a resume link to %s.', 'relevant-forms' ),
					$email
				)
				: __( 'Progress saved, but the link email could not be sent right now — try again in a few minutes.', 'relevant-forms' ),
			'token'      => $rawToken,
			'expires_at' => $expiresAt,
			'emailed'    => $emailed,
			'entry_id'   => $entryId,
		];
	}

	/**
	 * Locate the partial entry this save should update, if any.
	 *
	 * @param EntryRepository $repository Entry persistence.
	 * @param int             $formId     Form ID.
	 * @param string          $email      Requested email address.
	 * @param string          $token      Raw token from the request (may be empty).
	 */
	private static function findExisting( EntryRepository $repository, int $formId, string $email, string $token ): ?Entry {
		if ( '' !== $token ) {
			$candidate = $repository->findPartialByResumeHash( ResumeTokens::hash( $token ) );

			if ( null !== $candidate && $candidate->formId === $formId ) {
				return $candidate;
			}
		}

		return $repository->findPartialByEmail( $formId, $email );
	}

	/**
	 * Sanitize posted values through the same per-type pipeline as a real
	 * submission — without required-field validation. File fields are
	 * skipped (uploads are not preserved) and fields hidden by conditional
	 * logic keep no value.
	 *
	 * @param Form                 $form Form entity.
	 * @param array<string, mixed> $raw  Unslashed request payload.
	 * @return array<string, mixed>
	 */
	private static function sanitizeValues( Form $form, array $raw ): array {
		$definitions = $form->schema->fields();

		$display = [];

		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$fieldId = (string) ( $definition['id'] ?? '' );

			if ( '' === $fieldId ) {
				continue;
			}

			$display[ $fieldId ] = $raw[ 'relevant_field_' . $fieldId ] ?? ( $definition['default_value'] ?? '' );
		}

		$visibility = VisibilityRules::evaluate( $definitions, $display );

		$values = [];

		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$fieldId = (string) ( $definition['id'] ?? '' );
			$type    = FieldTypeRegistry::get( (string) ( $definition['type'] ?? '' ) );

			if ( '' === $fieldId || null === $type ) {
				continue;
			}

			if ( 'file' === $type->type() ) {
				continue;
			}

			if ( isset( $visibility[ $fieldId ] ) && false === $visibility[ $fieldId ] ) {
				continue;
			}

			$sanitized = $type->sanitize( $definition, $display[ $fieldId ] );

			if ( null !== $sanitized ) {
				$values[ $fieldId ] = $sanitized;
			}
		}

		return $values;
	}

	/**
	 * Resume URL for the email: the page URL plus the token query args.
	 *
	 * @param string $sourceUrl Page URL the form was saved from.
	 * @param string $token     Raw resume token.
	 * @param int    $formId    Form ID.
	 */
	public static function resumeUrl( string $sourceUrl, string $token, int $formId ): string {
		$base = wp_validate_redirect( (string) $sourceUrl, home_url( '/' ) );
		$base = remove_query_arg( [ 'relevant_resume', 'relevant_form' ], $base );

		return (string) add_query_arg(
			[
				'relevant_resume' => $token,
				'relevant_form'   => $formId,
			],
			$base
		);
	}

	/**
	 * Increment and test a transient rate-limit bucket.
	 *
	 * @param string $bucket Bucket name (ip|email).
	 * @param string $key    Bucket key (hashed).
	 * @param int    $limit  Allowed hits within the window.
	 * @param int    $window Window in seconds.
	 */
	private static function throttled( string $bucket, string $key, int $limit, int $window ): bool {
		if ( $limit <= 0 ) {
			return false;
		}

		$name  = 'relevant_forms_resume_' . $bucket . '_' . md5( $key );
		$count = (int) get_transient( $name );

		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $name, $count + 1, $window );

		return false;
	}

	/**
	 * Failure result in the service result shape.
	 *
	 * @param string $status  Machine status.
	 * @param string $message User-facing message.
	 * @return array{status: string, message: string, token: string, expires_at: string, emailed: bool, entry_id: int}
	 */
	private static function failure( string $status, string $message ): array {
		return [
			'status'     => $status,
			'message'    => $message,
			'token'      => '',
			'expires_at' => '',
			'emailed'    => false,
			'entry_id'   => 0,
		];
	}
}
