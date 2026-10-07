<?php
/**
 * Public form submission handling (doc §87 first milestone).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Http;

use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Entries\Resume\ResumeTokens;
use RelevantForms\Ai\AiClassifier;
use RelevantForms\Ai\AttentionScore;
use RelevantForms\Ai\Classification;
use RelevantForms\Events\EventRecorder;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRenderer;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\Fields\FieldTypeRegistry;
use RelevantForms\Forms\SubmissionFeedback;
use RelevantForms\Forms\Visibility\VisibilityRules;
use RelevantForms\Notifications\SubmissionNotifier;
use RelevantForms\Payments\CloudPaymentsGateway;
use RelevantForms\Payments\PaymentService;
use RelevantForms\Payments\PaymentTotals;
use RelevantForms\Support\Logger;
use RelevantForms\Support\Privacy;
use RelevantForms\Uploads\UploadService;
use RelevantForms\AntiSpam\SpamProtection;

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
		add_action( 'admin_post_nopriv_relevant_forms_submit', [ self::class, 'handle' ] );
		add_action( 'admin_post_relevant_forms_submit', [ self::class, 'handle' ] );
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

		[ $errors, $values, $display, $payment ] = self::validateSubmission( $form );

		if ( [] !== $errors ) {
			status_header( 422 );

			$body = FormRenderer::renderForm( $form, $errors, $display );

			// Test seam: allows integration tests to intercept the response.
			apply_filters( 'relevant_forms_invalid_submission_response', $body, 422, $form->id );

			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped markup.
			exit;
		}

		self::store( $form, $values, $payment );
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
			case 'relevant_forms_submit':
				self::handleFrontendSubmission();
				break;
			case 'relevant_forms_upload_authorize':
				UploadController::authorize();
				break;
			case 'relevant_forms_local_upload':
				UploadController::localUpload();
				break;
			case 'relevant_forms_upload_complete':
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

		[ $errors, $values, $display, $payment ] = self::validateSubmission( $form );

		if ( [] !== $errors ) {
			SubmissionFeedback::stash( $form->id, $errors, $display );

			return;
		}

		self::store( $form, $values, $payment );
	}

	/**
	 * Resolve the submitted form id.
	 */
	private static function resolveForm(): Form {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified by verifyNonce() before this value is used.
		$formId = isset( $_POST['_relevant_form_id'] ) ? absint( wp_unslash( $_POST['_relevant_form_id'] ) ) : 0;
		$form   = $formId > 0 ? ( new FormRepository() )->findPublished( $formId ) : null;

		if ( null === $form ) {
			wp_die( esc_html__( 'Form not found.', 'relevant-forms' ), '', [ 'response' => 404 ] );
		}

		return $form;
	}

	/**
	 * Verify the per-form nonce.
	 */
	private static function verifyNonce( Form $form ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this helper is the verification step for both routes.
		$nonce = isset( $_POST['_relevant_form_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_relevant_form_nonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'relevant_form_' . $form->id ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * Honeypot triggered: pretend success without storing anything.
	 */
	private static function guardHoneypot( Form $form ): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- honeypot value is compared only, never stored or output; nonce already verified.
		if ( isset( $_POST['_relevant_hp'] ) && '' !== (string) wp_unslash( $_POST['_relevant_hp'] ) ) {
			Logger::info( 'Honeypot triggered', [ 'form_id' => $form->id ] );
			self::finish( $form );
		}
	}

	/**
	 * Field validation shared by both routes.
	 *
	 * @return array{0: array<string, string>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>} Errors (field id → message), sanitized values, raw display values and the server-computed payment breakdown.
	 */
	private static function validateSubmission( Form $form ): array {
		// The first field is always shown: rules can only depend on fields
		// above, and nothing sits above the first field (FormRenderer).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified by verifyNonce() before validation runs.
		$raw         = wp_unslash( $_POST );
		$values      = [];
		$display     = [];
		$errors      = [];
		$definitions = VisibilityRules::withoutFirstFieldRules( $form->schema->fields() );
		$currency    = $form->schema->currency();

		$context = [];

		foreach ( $definitions as $definition ) {
			$fieldId             = (string) ( $definition['id'] ?? '' );
			$context[ $fieldId ] = $raw[ 'relevant_field_' . $fieldId ] ?? ( $definition['default_value'] ?? '' );
		}

		$visibility = VisibilityRules::evaluate( $definitions, $context );

		foreach ( $definitions as $definition ) {
			// Payment math parses decimals per form currency.
			$definition['currency'] = $currency;

			$fieldId = (string) ( $definition['id'] ?? '' );
			$type    = FieldTypeRegistry::get( (string) ( $definition['type'] ?? '' ) );

			if ( null === $type || '' === $fieldId ) {
				continue;
			}

			$value = $raw[ 'relevant_field_' . $fieldId ] ?? ( $definition['default_value'] ?? '' );

			if ( 'file' === $type->type() ) {
				$value = $raw[ 'relevant_upload_' . $fieldId . '_ids' ] ?? '';
			}

			$display[ $fieldId ] = $value;

			// Fields hidden by their visibility rules keep the entered value
			// for re-display, but skip validation and storage entirely — the
			// behaviour users expect from conditional logic.
			if ( isset( $visibility[ $fieldId ] ) && false === $visibility[ $fieldId ] ) {
				continue;
			}

			$error = $type->validate( $definition, $value );

			if ( null === $error && ! empty( $definition['required'] ) && self::isEmpty( $value ) ) {
				$error = __( 'This field is required.', 'relevant-forms' );
			}

			if ( null !== $error ) {
				$errors[ $fieldId ] = $error;
				continue;
			}

			$sanitized = $type->sanitize( $definition, $value );

			// Fields that carry no value (section separators) are left out
			// of the stored values entirely: no meta row, no email line.
			if ( null !== $sanitized ) {
				$values[ $fieldId ] = $sanitized;
			}
		}

		// Compatibility gate (Phase 7.5): WPForms-style consumers may halt
		// a submission here via relevant_forms_pre_insert_errors.
		$errors = array_merge(
			$errors,
			(array) apply_filters( 'relevant_forms_pre_insert_errors', [], $form, $values )
		);

		return [ $errors, $values, $display, self::paymentBreakdown( $form, $raw, $visibility ) ];
	}

	/**
	 * Server-computed payment breakdown for the submission (^ payments doc
	 * §2–3): amounts come from the field definitions and posted positions,
	 * never from posted prices. Fields hidden by visibility rules contribute
	 * nothing.
	 *
	 * @param Form                  $form       Form entity.
	 * @param array<string, mixed>  $raw        Unslashed POST data.
	 * @param array<string, bool>   $visibility Computed visibility per field id.
	 * @return array{currency: string, total_minor: int, items: array<int, array{field_id: string, label: string, detail: string, amount_minor: int}>}
	 */
	private static function paymentBreakdown( Form $form, array $raw, array $visibility ): array {
		$definitions = $form->schema->fields();
		$currency    = $form->schema->currency();

		if ( ! PaymentTotals::hasPaymentFields( $definitions ) ) {
			return [
				'currency'    => $currency,
				'total_minor' => 0,
				'items'       => [],
			];
		}

		$rawById = [];

		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$fieldId = (string) ( $definition['id'] ?? '' );

			if ( '' === $fieldId ) {
				continue;
			}

			if ( isset( $visibility[ $fieldId ] ) && false === $visibility[ $fieldId ] ) {
				continue;
			}

			if ( array_key_exists( 'relevant_field_' . $fieldId, $raw ) ) {
				$rawById[ $fieldId ] = $raw[ 'relevant_field_' . $fieldId ];
			}
		}

		return PaymentTotals::breakdown( $definitions, $rawById, $currency );
	}

	/**
	 * Partial entry this submission completes, when the POST carries a valid
	 * Save and Resume token (docs/hooks.md). Stale or tampered tokens return
	 * null: the submission then proceeds as a fresh entry.
	 *
	 * @param Form                 $form Form entity.
	 * @param array<string, mixed> $raw  Unslashed POST data.
	 */
	private static function resumedPartial( Form $form, array $raw ): ?Entry {
		$token = isset( $raw['_relevant_resume_token'] ) ? (string) $raw['_relevant_resume_token'] : '';
		$token = preg_replace( '/[^0-9a-f]/', '', strtolower( $token ) );

		if ( ! is_string( $token ) || 64 !== strlen( $token ) ) {
			return null;
		}

		$entry = ( new EntryRepository() )->findPartialByResumeHash( ResumeTokens::hash( $token ) );

		if ( null === $entry || $entry->formId !== $form->id || Entry::STATUS_PARTIAL !== $entry->status ) {
			return null;
		}

		return $entry;
	}

	/**
	 * Store a validated submission, record events, notify (or defer the
	 * notifications for payments), then redirect.
	 *
	 * @param array<string, mixed>                                                                                                                   $values  Sanitized field values.
	 * @param array{currency: string, total_minor: int, items: array<int, array{field_id: string, label: string, detail: string, amount_minor: int}>} $payment Server-computed payment breakdown.
	 */
	private static function store( Form $form, array $values, array $payment ): never {
		$repository = new FormRepository();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified by verifyNonce() before storing.
		$raw = wp_unslash( $_POST );

		try {
			$startedAt  = isset( $raw['_relevant_started_at'] ) ? (float) $raw['_relevant_started_at'] : null;
			$protection = ( new SpamProtection() )->evaluate( $form->id, $values, Privacy::ipHash(), $startedAt );

			$classifier       = new AiClassifier();
			$classificationOn = $classifier->isEnabled( $form );
			$classification   = $classificationOn ? $classifier->classify( $form, $values, $protection ) : null;

			$entryStatus = self::resolveStatus( $protection['status'], $classification );

			// Payment forms hold the entry (and its notifications) until the
			// checkout completes; purely local spam never reaches checkout.
			$paymentPending = (int) ( $payment['total_minor'] ?? 0 ) > 0 && Entry::STATUS_SPAM !== $entryStatus;

			if ( $paymentPending ) {
				$entryStatus = Entry::STATUS_PENDING_PAYMENT;
			}

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

			$entries   = new EntryRepository();
			$versionId = $repository->createVersion( $form->id, $form->schema );
			$partial   = self::resumedPartial( $form, $raw );

			if ( null !== $partial ) {
				// Save and Resume: the recovered partial becomes the final
				// entry — one submission, one entry, original created_at kept.
				$meta['_resumed'] = 1;

				$entries->completePartial(
					(int) $partial->id,
					$versionId,
					$entryStatus,
					$meta,
					self::requestUrl(),
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- stored only, escaped on display.
					(string) ( $_SERVER['HTTP_REFERER'] ?? '' )
				);

				$entryId = (int) $partial->id;

				EventRecorder::record( $entryId, 'resume_completed', [ 'form_id' => $form->id ] );
			} else {
				$entry = new Entry(
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

				$entryId = $entries->create( $entry );
			}
		} catch ( \Throwable $exception ) {
			Logger::error(
				'Failed to store submission',
				[
					'form_id'   => $form->id,
					'exception' => $exception->getMessage(),
				]
			);
			wp_die( esc_html__( 'Your submission could not be stored. Please try again later.', 'relevant-forms' ), '', [ 'response' => 500 ] );
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

		do_action( 'relevant_forms_submission_received', $entryId, $form->id, $values );

		if ( $paymentPending ) {
			$service = new PaymentService();
			$service->recordPending( $entryId, $payment );

			EventRecorder::record( $entryId, 'notification_deferred', [ 'reason' => 'pending_payment' ] );

			self::startPayment( $form, $entryId, $payment, $service );

			self::finish( $form );
		}

		if ( Entry::STATUS_SPAM !== $entryStatus ) {
			( new SubmissionNotifier() )->notify( $form, $entryId, $classification, $values );
		} else {
			EventRecorder::record( $entryId, 'notification_skipped', [ 'reason' => 'spam' ] );
		}

		self::finish( $form );
	}

	/**
	 * Hand a pending entry to the checkout service and forward the visitor
	 * to the hosted payment page. When the service is unavailable or the
	 * call fails the entry simply stays pending and the normal confirmation
	 * flow continues — never a dead end for the visitor (payments doc §4).
	 *
	 * @param Form                 $form    Form entity.
	 * @param int                  $entryId Stored entry ID.
	 * @param array<string, mixed> $payment Breakdown recorded on the entry.
	 * @param PaymentService       $service Payment state service.
	 */
	private static function startPayment( Form $form, int $entryId, array $payment, PaymentService $service ): void {
		$gateway = new CloudPaymentsGateway();
		$session = $gateway->isAvailable()
			? $gateway->createSession( $form, $entryId, $payment, self::requestUrl() )
			: null;

		if ( null === $session ) {
			self::failPaymentSession( $entryId, $form->id );

			return;
		}

		$checkout = esc_url_raw( (string) ( $session['checkout_url'] ?? '' ) );

		if ( ! in_array( (string) wp_parse_url( $checkout, PHP_URL_SCHEME ), [ 'http', 'https' ], true ) ) {
			self::failPaymentSession( $entryId, $form->id );

			return;
		}

		$service->recordSession( $entryId, $session );

		do_action( 'relevant_forms_payment_redirect', $session, $entryId, $form->id );

		// Off-site by design: `wp_safe_redirect()` would DNS-validate the
		// checkout host and silently fall back to wp-admin when resolution
		// fails — unacceptable mid-payment. The URL is scheme-checked above
		// and produced by our own cloud for this entry.
		wp_redirect( $checkout ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated absolute http(s) checkout URL.
		exit;
	}

	/**
	 * Checkout unavailable: the entry stays pending and the visitor falls
	 * back to the normal confirmation (payments doc §4).
	 *
	 * @param int $entryId Stored entry ID.
	 * @param int $formId  Form ID.
	 */
	private static function failPaymentSession( int $entryId, int $formId ): void {
		Logger::warning(
			'Payment session could not be created',
			[
				'entry_id' => $entryId,
				'form_id'  => $formId,
			]
		);
		EventRecorder::record( $entryId, 'payment_session_failed', [ 'form_id' => $formId ] );
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

		return (string) apply_filters( 'relevant_forms_entry_status', $status, $classification, $localStatus );
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
		$redirect = self::redirectTarget( $form );

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
				'relevant_form_success',
				$form->id,
				'' !== $referer ? $referer : home_url( '/' )
			);
		}

		$url = (string) apply_filters( 'relevant_forms_redirect', $url, $form->id );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Resolve the redirect target for the active confirmation mode: the
	 * anchored page/post's current permalink (immutable across slug changes)
	 * or the configured URL. Message mode — and unresolvable targets — return
	 * an empty string so the submission falls back to the on-page message.
	 *
	 * @param Form $form Form definition.
	 */
	private static function redirectTarget( Form $form ): string {
		$mode = $form->schema->confirmationMode();

		if ( 'page' === $mode ) {
			$pageId = $form->schema->redirectPageId();

			if ( $pageId > 0 && 'publish' === get_post_status( $pageId ) ) {
				$permalink = get_permalink( $pageId );

				if ( is_string( $permalink ) && '' !== $permalink ) {
					return $permalink;
				}
			}

			return '';
		}

		if ( 'url' === $mode ) {
			return $form->schema->redirectUrl();
		}

		return '';
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
