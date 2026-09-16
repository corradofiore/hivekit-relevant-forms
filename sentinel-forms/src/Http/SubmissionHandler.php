<?php
/**
 * Public form submission handling (doc §87 first milestone).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Http;

use SentinelForms\Entries\Entry;
use SentinelForms\Entries\EntryRepository;
use SentinelForms\Ai\AiClassifier;
use SentinelForms\Ai\AttentionScore;
use SentinelForms\Ai\Classification;
use SentinelForms\Alerts\SmsAlert;
use SentinelForms\Events\EventRecorder;
use SentinelForms\Forms\Form;
use SentinelForms\Forms\FormRenderer;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\Fields\FieldTypeRegistry;
use SentinelForms\Forms\SubmissionFeedback;
use SentinelForms\Notifications\EmailNotification;
use SentinelForms\Support\Logger;
use SentinelForms\Support\Privacy;
use SentinelForms\Uploads\UploadService;
use SentinelForms\AntiSpam\SpamProtection;

/**
 * Handles form submissions for guests and logged-in users. Rendered forms
 * post to their own page (front-end handler); `admin-post.php` stays as a
 * back-compat route for older markup and direct integrations.
 */
final class SubmissionHandler {

	/**
	 * Register handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_nopriv_sentinel_forms_submit', [ self::class, 'handle' ] );
		add_action( 'admin_post_sentinel_forms_submit', [ self::class, 'handle' ] );
		add_action( 'template_redirect', [ self::class, 'maybeHandleFrontendSubmission' ] );
	}

	/**
	 * Legacy route: direct POSTs to `admin-post.php` keep the historic
	 * behaviour (bare 422 render). Kept for older cached markup and direct
	 * integrations; new markup posts to the form's own page.
	 */
	public static function handle(): void {
		$form = self::resolveForm();

		self::verifyNonce( $form );
		self::guardHoneypot( $form );

		[ $errors, $values, $display ] = self::validateSubmission( $form );

		if ( [] !== $errors ) {
			status_header( 422 );

			$body = FormRenderer::renderForm( $form, $errors, $display );

			// Test seam: allows integration tests to intercept the response.
			apply_filters( 'sentinel_forms_invalid_submission_response', $body, 422, $form->id );

			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped markup.
			exit;
		}

		self::store( $form, $values );
	}

	/**
	 * Front-end POSTs to the page a form lives on: submissions render
	 * inline (themed page, entered values preserved) and the upload helper
	 * actions are served from here too, so user-facing pages never post
	 * to `wp-admin`.
	 */
	public static function maybeHandleFrontendSubmission(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( 'POST' !== strtoupper( $method ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- dispatch only; each branch verifies its own nonce before use.
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		switch ( $action ) {
			case 'sentinel_forms_submit':
				self::handleFrontendSubmission();
				break;
			case 'sentinel_forms_upload_authorize':
				UploadController::authorize();
				break;
			case 'sentinel_forms_local_upload':
				UploadController::localUpload();
				break;
			case 'sentinel_forms_upload_complete':
				UploadController::complete();
				break;
		}
	}

	/**
	 * In-page submission: validation errors are stashed for the renderer
	 * (same request, HTTP 200) and successes redirect back (PRG).
	 */
	private static function handleFrontendSubmission(): void {
		$form = self::resolveForm();

		self::verifyNonce( $form );
		self::guardHoneypot( $form );

		[ $errors, $values, $display ] = self::validateSubmission( $form );

		if ( [] !== $errors ) {
			SubmissionFeedback::stash( $form->id, $errors, $display );

			return;
		}

		self::store( $form, $values );
	}

	/**
	 * Resolve the submitted form id.
	 */
	private static function resolveForm(): Form {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified by verifyNonce() before this value is used.
		$formId = isset( $_POST['_sentinel_form_id'] ) ? absint( wp_unslash( $_POST['_sentinel_form_id'] ) ) : 0;
		$form   = $formId > 0 ? ( new FormRepository() )->findPublished( $formId ) : null;

		if ( null === $form ) {
			wp_die( esc_html__( 'Form not found.', 'sentinel-forms' ), '', [ 'response' => 404 ] );
		}

		return $form;
	}

	/**
	 * Verify the per-form nonce.
	 */
	private static function verifyNonce( Form $form ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this helper is the verification step for both routes.
		$nonce = isset( $_POST['_sentinel_form_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sentinel_form_nonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'sentinel_form_' . $form->id ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * Honeypot triggered: pretend success without storing anything.
	 */
	private static function guardHoneypot( Form $form ): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- honeypot value is compared only, never stored or output; nonce already verified.
		if ( isset( $_POST['_sentinel_hp'] ) && '' !== (string) wp_unslash( $_POST['_sentinel_hp'] ) ) {
			Logger::info( 'Honeypot triggered', [ 'form_id' => $form->id ] );
			self::finish( $form );
		}
	}

	/**
	 * Field validation shared by both routes.
	 *
	 * @return array{0: array<string, string>, 1: array<string, mixed>, 2: array<string, mixed>} Errors (field id → message), sanitized values and raw display values.
	 */
	private static function validateSubmission( Form $form ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified by verifyNonce() before validation runs.
		$raw     = wp_unslash( $_POST );
		$values  = [];
		$display = [];
		$errors  = [];

		foreach ( $form->schema->fields() as $definition ) {
			$fieldId = (string) ( $definition['id'] ?? '' );
			$type    = FieldTypeRegistry::get( (string) ( $definition['type'] ?? '' ) );

			if ( null === $type || '' === $fieldId ) {
				continue;
			}

			$value = $raw[ 'sentinel_field_' . $fieldId ] ?? ( $definition['default_value'] ?? '' );

			if ( 'file' === $type->type() ) {
				$value = $raw[ 'sentinel_upload_' . $fieldId . '_ids' ] ?? '';
			}

			$display[ $fieldId ] = $value;

			$error = $type->validate( $definition, $value );

			if ( null === $error && ! empty( $definition['required'] ) && self::isEmpty( $value ) ) {
				$error = __( 'This field is required.', 'sentinel-forms' );
			}

			if ( null !== $error ) {
				$errors[ $fieldId ] = $error;
				continue;
			}

			$values[ $fieldId ] = $type->sanitize( $definition, $value );
		}

		// Compatibility gate (Phase 7.5): WPForms-style consumers may halt
		// a submission here via sentinel_forms_pre_insert_errors.
		$errors = array_merge(
			$errors,
			(array) apply_filters( 'sentinel_forms_pre_insert_errors', [], $form, $values )
		);

		return [ $errors, $values, $display ];
	}

	/**
	 * Store a validated submission, record events, notify, then redirect.
	 *
	 * @param array<string, mixed> $values Sanitized field values.
	 */
	private static function store( Form $form, array $values ): never {
		$repository = new FormRepository();
		$raw        = wp_unslash( $_POST );

		try {
			$startedAt  = isset( $raw['_sentinel_started_at'] ) ? (float) $raw['_sentinel_started_at'] : null;
			$protection = ( new SpamProtection() )->evaluate( $form->id, $values, Privacy::ipHash(), $startedAt );

			$classifier       = new AiClassifier();
			$classificationOn = $classifier->isEnabled( $form );
			$classification   = $classificationOn ? $classifier->classify( $form, $values, $protection ) : null;

			$entryStatus = self::resolveStatus( $protection['status'], $classification );

			$meta = array_merge(
				$values,
				[
					'_spam_signals'    => $protection['signals']->toArray(),
					'_submission_hash' => $protection['hash'],
					'_spam_score'      => $protection['score'],
				]
			);

			if ( null !== $classification ) {
				$meta['_classification']  = $classification->toArray();
				$meta['_attention_score'] = $classification->attentionScore;
			}

			$versionId = $repository->createVersion( $form->id, $form->schema );
			$entry     = new Entry(
				null,
				$form->id,
				$versionId,
				$entryStatus,
				self::requestUrl(),
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- stored only, escaped on display.
				(string) ( $_SERVER['HTTP_REFERER'] ?? '' ),
				Privacy::userAgentHash(),
				Privacy::ipHash(),
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				$meta,
			);
			$entryId = ( new EntryRepository() )->create( $entry );
		} catch ( \Throwable $exception ) {
			Logger::error(
				'Failed to store submission',
				[
					'form_id'   => $form->id,
					'exception' => $exception->getMessage(),
				]
			);
			wp_die( esc_html__( 'Your submission could not be stored. Please try again later.', 'sentinel-forms' ), '', [ 'response' => 500 ] );
		}

		EventRecorder::record( $entryId, 'submission_received', [ 'form_id' => $form->id ] );

		EventRecorder::record(
			$entryId,
			'spam_scored',
			[
				'score'  => $protection['score'],
				'status' => $entryStatus,
			]
		);

		if ( $classificationOn ) {
			if ( null !== $classification ) {
				EventRecorder::record(
					$entryId,
					'classification_completed',
					[
						'reason_code'      => $classification->reasonCode,
						'attention_score'  => $classification->attentionScore,
						'spam_probability' => $classification->spamProbability,
					]
				);
			} else {
				EventRecorder::record( $entryId, 'classification_failed', [ 'form_id' => $form->id ] );
			}
		}

		self::attachUploads( $form, $entryId, $values );

		do_action( 'sentinel_forms_submission_received', $entryId, $form->id, $values );

		if ( null !== $classification && Entry::STATUS_SPAM !== $entryStatus ) {
			self::notifySmsIfNeeded( $form, $entryId, $classification );
		}

		if ( Entry::STATUS_SPAM !== $entryStatus ) {
			( new EmailNotification() )->send( $form, $values, $entryId );
		} else {
			EventRecorder::record( $entryId, 'notification_skipped', [ 'reason' => 'spam' ] );
		}

		self::finish( $form );
	}

	/**
	 * Managed priority SMS (Phase 5, doc §20–22): interrupt the operator
	 * when attention >= threshold. The Cloud renders the message from the
	 * minimal payload (summary + secure entry link); failures only leave
	 * a recorded event — entry and email remain unaffected (doc §57).
	 *
	 * @param Form           $form           Form entity.
	 * @param int            $entryId        Stored entry ID.
	 * @param Classification $classification Validated classification.
	 */
	private static function notifySmsIfNeeded( Form $form, int $entryId, Classification $classification ): void {
		$alert = new SmsAlert();

		if ( ! $alert->shouldSend( $classification ) ) {
			return;
		}

		try {
			$delivery = $alert->send( $form, $entryId, $classification );

			( new EntryRepository() )->updateMeta(
				$entryId,
				'_sms_alert',
				null !== $delivery
					? [
						'status'      => (string) ( $delivery['status'] ?? 'queued' ),
						'delivery_id' => (int) ( $delivery['delivery_id'] ?? 0 ),
						'at'          => current_time( 'mysql', true ),
					]
					: [
						'status' => 'failed',
						'at'     => current_time( 'mysql', true ),
					]
			);

			EventRecorder::record(
				$entryId,
				null !== $delivery ? 'sms_alert_sent' : 'sms_alert_failed',
				[
					'attention_score' => $classification->attentionScore,
					'delivery_id'     => (int) ( $delivery['delivery_id'] ?? 0 ),
				]
			);
		} catch ( \Throwable $exception ) {
			Logger::warning(
				'Managed SMS step failed',
				[
					'entry_id'  => $entryId,
					'exception' => $exception->getMessage(),
				]
			);
		}
	}

	/**
	 * Resolve the stored entry status from the local evaluation and the
	 * (optional) Cloud classification (doc §20). Classification can only
	 * escalate: spam >= 0.95 becomes spam, >= 0.70 suspicious, attention
	 * >= 85 priority. Local spam is never downgraded.
	 *
	 * @param string              $localStatus    Local anti-spam status.
	 * @param Classification|null $classification Cloud classification.
	 */
	private static function resolveStatus( string $localStatus, ?Classification $classification ): string {
		if ( Entry::STATUS_SPAM === $localStatus || null === $classification ) {
			return $localStatus;
		}

		if ( $classification->spamProbability >= 0.95 ) {
			return Entry::STATUS_SPAM;
		}

		if ( $classification->spamProbability >= 0.70 ) {
			return Entry::STATUS_SUSPICIOUS;
		}

		$status = AttentionScore::isPriority( $classification->attentionScore )
			? Entry::STATUS_PRIORITY
			: $localStatus;

		return (string) apply_filters( 'sentinel_forms_entry_status', $status, $classification, $localStatus );
	}

	/**
	 * Attach claimed uploads to the stored entry (doc §28).
	 *
	 * @param Form                  $form    Submitted form.
	 * @param int                   $entryId Stored entry ID.
	 * @param array<string, mixed>  $values  Sanitized values.
	 */
	private static function attachUploads( Form $form, int $entryId, array $values ): void {
		$service = new UploadService();

		foreach ( $form->schema->fields() as $definition ) {
			if ( 'file' !== (string) ( $definition['type'] ?? '' ) ) {
				continue;
			}

			$fieldId = (string) ( $definition['id'] ?? '' );
			$json    = (string) ( $values[ $fieldId ] ?? '' );
			$decoded = json_decode( $json, true );
			$ids     = is_array( $decoded ) ? array_filter( array_map( 'absint', $decoded ) ) : [];

			if ( [] === $ids ) {
				continue;
			}

			$service->attachToEntry( $entryId, $fieldId, $ids );
			EventRecorder::record(
				$entryId,
				'upload_attached',
				[
					'field_id' => $fieldId,
					'uploads'  => $ids,
				]
			);
		}
	}

	/**
	 * Redirect after processing. Filterable for tests and integrations.
	 */
	private static function finish( Form $form ): never {
		$redirect = $form->schema->redirectUrl();

		if ( '' !== $redirect && filter_var( $redirect, FILTER_VALIDATE_URL ) !== false ) {
			$url = $redirect;
		} else {
			// `wp_get_referer()` returns false when the referer equals the
			// current request URI (core's redirect-loop guard) — exactly the
			// front-end flow, where the form posts to its own page. Fall back
			// to the raw referer with the same validation.
			$referer = wp_get_referer();

			if ( false === $referer ) {
				$referer = wp_validate_redirect( (string) wp_get_raw_referer(), '' );
			}

			$url = add_query_arg(
				'sentinel_form_success',
				$form->id,
				'' !== $referer ? $referer : home_url( '/' )
			);
		}

		$url = (string) apply_filters( 'sentinel_forms_redirect', $url, $form->id );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Whether a submitted value is empty.
	 */
	private static function isEmpty( mixed $value ): bool {
		return '' === $value || null === $value || [] === $value;
	}

	/**
	 * The page URL the form was submitted from (form host).
	 */
	private static function requestUrl(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- stored only, escaped on display.
		$url = (string) ( $_SERVER['HTTP_REFERER'] ?? '' );

		return '' !== $url ? $url : home_url( '/' );
	}
}
