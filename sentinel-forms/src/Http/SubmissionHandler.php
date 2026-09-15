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
use SentinelForms\Events\EventRecorder;
use SentinelForms\Forms\Form;
use SentinelForms\Forms\FormRenderer;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\Fields\FieldTypeRegistry;
use SentinelForms\Notifications\EmailNotification;
use SentinelForms\Support\Logger;
use SentinelForms\Support\Privacy;
use SentinelForms\Uploads\UploadService;
use SentinelForms\AntiSpam\SpamProtection;

/**
 * Handles POST submissions via admin-post.php for guests and logged-in users.
 */
final class SubmissionHandler {

	/**
	 * Register handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_nopriv_sentinel_forms_submit', [ self::class, 'handle' ] );
		add_action( 'admin_post_sentinel_forms_submit', [ self::class, 'handle' ] );
	}

	/**
	 * Process a submission.
	 */
	public static function handle(): void {
		$repository = new FormRepository();

		$formId = isset( $_POST['_sentinel_form_id'] ) ? absint( wp_unslash( $_POST['_sentinel_form_id'] ) ) : 0;
		$form   = $formId > 0 ? $repository->findPublished( $formId ) : null;

		if ( null === $form ) {
			wp_die( esc_html__( 'Form not found.', 'sentinel-forms' ), '', [ 'response' => 404 ] );
		}

		if ( ! isset( $_POST['_sentinel_form_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sentinel_form_nonce'] ) ), 'sentinel_form_' . $formId ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		// Honeypot triggered: pretend success without storing anything.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- honeypot value is compared only, never stored or output.
		if ( isset( $_POST['_sentinel_hp'] ) && '' !== (string) wp_unslash( $_POST['_sentinel_hp'] ) ) {
			Logger::info( 'Honeypot triggered', [ 'form_id' => $formId ] );
			self::finish( $form );
		}

		$raw    = wp_unslash( $_POST );
		$values = [];
		$errors = [];

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

		if ( $errors !== [] ) {
			status_header( 422 );

			$body = FormRenderer::renderForm( $form, $errors, $values );

			// Test seam: allows integration tests to intercept the response.
			apply_filters( 'sentinel_forms_invalid_submission_response', $body, 422, $form->id );

			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped markup.
			exit;
		}

		try {
			$startedAt  = isset( $raw['_sentinel_started_at'] ) ? (float) $raw['_sentinel_started_at'] : null;
			$protection = ( new SpamProtection() )->evaluate( $form->id, $values, Privacy::ipHash(), $startedAt );

			$entryStatus = $protection['status'];
			$meta        = array_merge(
				$values,
				[
					'_spam_signals'    => $protection['signals']->toArray(),
					'_submission_hash' => $protection['hash'],
					'_spam_score'      => $protection['score'],
				]
			);

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

		self::attachUploads( $form, $entryId, $values );

		do_action( 'sentinel_forms_submission_received', $entryId, $form->id, $values );

		if ( Entry::STATUS_SPAM !== $entryStatus ) {
			( new EmailNotification() )->send( $form, $values, $entryId );
		} else {
			EventRecorder::record( $entryId, 'notification_skipped', [ 'reason' => 'spam' ] );
		}

		self::finish( $form );
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
			$url = add_query_arg(
				'sentinel_form_success',
				$form->id,
				wp_get_referer() !== false ? wp_get_referer() : home_url( '/' )
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
