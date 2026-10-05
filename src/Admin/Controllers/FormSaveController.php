<?php
/**
 * Form save controller (admin-post + admin-ajax actions).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Controllers;

use RelevantForms\Alerts\PhoneNumber;
use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Forms\Fields\FieldTypeRegistry;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Forms\Visibility\VisibilityRules;
use RelevantForms\Payments\Money;
use RelevantForms\Support\Ajax;
use RelevantForms\Support\Logger;
use RelevantForms\Support\TextList;

/**
 * Persists the form builder payload.
 */
final class FormSaveController {

	/**
	 * Register the admin-post and admin-ajax actions.
	 */
	public static function register(): void {
		add_action( 'admin_post_relevant_forms_save_form', [ self::class, 'handle' ] );
		add_action( 'wp_ajax_relevant_forms_save_form', [ self::class, 'handleAjax' ] );
	}

	/**
	 * Handle the classic (no-JS) save request.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'relevant_form_save' );

		$result = self::save();

		if ( $result['errors'] !== [] ) {
			$editUrl = admin_url( 'admin.php?page=relevant-form-edit' . ( $result['id'] > 0 ? '&form_id=' . $result['id'] : '' ) );

			wp_die(
				'<h1>' . esc_html__( 'The form could not be saved.', 'relevant-forms' ) . '</h1>'
				. '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $result['errors'] ) ) . '</li></ul>'
				. '<p><a href="' . esc_url( $editUrl ) . '">&larr; ' . esc_html__( 'Go back', 'relevant-forms' ) . '</a></p>',
				esc_html__( 'Form invalid', 'relevant-forms' ),
				[ 'response' => 400 ]
			);
		}

		wp_safe_redirect(
			admin_url(
				'admin.php?page=relevant-form-edit&form_id=' . $result['id'] . '&saved=1'
				. ( '' !== $result['sms_error'] ? '&sms_error=' . rawurlencode( $result['sms_error'] ) : '' )
			)
		);
		exit;
	}

	/**
	 * Handle the AJAX save request: same payload, JSON envelope, no reload —
	 * the editor keeps the tab the user is working on.
	 */
	public static function handleAjax(): void {
		Ajax::ensureManager( __( 'You do not have permission to perform this action.', 'relevant-forms' ) );

		check_ajax_referer( 'relevant_form_save' );

		$result = self::save();

		if ( $result['errors'] !== [] ) {
			Ajax::fail( 'invalid_form', implode( ' ', $result['errors'] ), 400 );
		}

		wp_send_json_success(
			[
				'form_id' => $result['id'],
				'created' => $result['created'],
				'message' => $result['created']
					? __( 'Form created.', 'relevant-forms' )
					: __( 'Form saved.', 'relevant-forms' ),
				'warning' => 'unverified_phone' === $result['sms_error']
					? __( 'The phone number was not saved: verify it with the 6-digit code first, then save again.', 'relevant-forms' )
					: '',
			]
		);
	}

	/**
	 * Persist the posted editor payload (shared by both entry points).
	 *
	 * @return array{errors: list<string>, id: int, created: bool, sms_error: string}
	 */
	private static function save(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Both callers verify the 'relevant_form_save' nonce first.
		$raw = wp_unslash( $_POST );

		$formId = isset( $raw['form_id'] ) ? absint( $raw['form_id'] ) : 0;
		$name   = sanitize_text_field( (string) ( $raw['form_name'] ?? '' ) );
		$status = (string) ( $raw['form_status'] ?? '' );

		if ( ! in_array( $status, [ 'draft', 'published', 'archived' ], true ) ) {
			$status = 'draft';
		}

		$repository = new FormRepository();
		$existing   = $formId > 0 ? $repository->find( $formId ) : null;

		$fields = self::parseFields( $raw['relevant_fields'] ?? [] );

		$emails  = self::parseLines( (string) ( $raw['notification_emails'] ?? '' ) );
		$message = sanitize_textarea_field( (string) ( $raw['confirmation_message'] ?? '' ) );

		$senderName  = sanitize_text_field( (string) ( $raw['sender_name'] ?? '' ) );
		$senderEmail = sanitize_email( (string) ( $raw['sender_email'] ?? '' ) );

		// Hidden rows still submit, so every key normally arrives; a missing
		// key (hand-crafted request) keeps the stored value instead of
		// clearing it.
		$redirect = array_key_exists( 'redirect_url', $raw )
			? esc_url_raw( (string) $raw['redirect_url'] )
			: ( null !== $existing ? $existing->schema->redirectUrl() : '' );

		$redirectPage = array_key_exists( 'redirect_page', $raw )
			? absint( $raw['redirect_page'] )
			: ( null !== $existing ? $existing->schema->redirectPageId() : 0 );

		// Missing key (hand-crafted request) keeps the stored currency.
		$currency = array_key_exists( 'form_currency', $raw )
			? Money::normalizeCurrency( (string) $raw['form_currency'] )
			: ( null !== $existing ? $existing->schema->currency() : Money::DEFAULT_CURRENCY );

		$mode = (string) ( $raw['confirmation_mode'] ?? '' );

		if ( ! in_array( $mode, FormSchema::CONFIRMATION_MODES, true ) ) {
			// Missing or unknown (e.g. legacy request): infer from the values.
			$mode = null;
		}

		$smsAlert = ! empty( $raw['ai_sms_alert'] );
		$smsPhone = null !== $existing ? (string) ( $existing->schema->aiPolicy()['sms_phone'] ?? '' ) : '';
		$smsError = '';

		if ( $smsAlert ) {
			$submitted  = trim( (string) ( $raw['ai_sms_phone'] ?? '' ) );
			$normalized = PhoneNumber::normalize( $submitted );

			if ( '' === $submitted ) {
				// Option on without a recipient: nothing is sent (alert
				// recipients are per form only).
				$smsPhone = '';
			} elseif ( null !== $normalized && ( $normalized === $smsPhone || SmsVerificationController::isVerified( $normalized ) ) ) {
				$smsPhone = $normalized;
			} else {
				// Inserting or changing a number requires a fresh 6-digit OTP
				// proof (SmsVerificationController); the submitted number is not
				// accepted and the stored value is kept.
				$smsError = 'unverified_phone';
			}
		}

		$aiPolicy = [
			'enabled'      => ! empty( $raw['ai_enabled'] ),
			'purpose'      => mb_substr( sanitize_textarea_field( (string) ( $raw['ai_purpose'] ?? '' ) ), 0, 500 ),
			'priorities'   => mb_substr( sanitize_textarea_field( (string) ( $raw['ai_priorities'] ?? '' ) ), 0, 2000 ),
			'spam'         => mb_substr( sanitize_textarea_field( (string) ( $raw['ai_spam'] ?? '' ) ), 0, 500 ),
			'privacy_mode' => empty( $raw['ai_privacy_mode'] ) ? 0 : 1,
			'sms_alert'    => $smsAlert,
			'sms_phone'    => $smsPhone,
		];

		$schema = FormSchema::empty()
			->withName( $name )
			->withFields( $fields )
			->withNotifications( $emails )
			->withSender( $senderName, $senderEmail )
			->withConfirmation( $message, $redirect, $redirectPage, $mode )
			->withAiPolicy( $aiPolicy )
			->withCurrency( $currency )
			// Preserve non-editor keys (import provenance, future top-level
			// upload policy) instead of resetting them on every save.
			->withMetadata( null !== $existing ? $existing->schema->metadata() : [] )
			->withUploadPolicy( null !== $existing ? $existing->schema->uploadPolicy() : [] );

		$errors = [];
		if ( '' === trim( $name ) ) {
			$errors[] = __( 'The form name is required.', 'relevant-forms' );
		}
		$errors = array_merge( $errors, $schema->validate() );

		if ( $errors !== [] ) {
			return [
				'errors'    => $errors,
				'id'        => $formId,
				'created'   => false,
				'sms_error' => '',
			];
		}

		$created = ! ( $formId > 0 );

		if ( ! $created ) {
			$repository->update( $formId, $name, $schema, $status );
		} else {
			$formId = $repository->create( $name, $schema, $status );
		}

		Logger::info(
			'Form saved',
			[
				'form_id' => $formId,
				'status'  => $status,
			]
		);

		// The per-form opt-ins are the explicit consent actions (there is no
		// site-wide switch): saving with classification on — or with a
		// verified alert number — grants the matching Cloud capability.
		// Unchecking never revokes: other forms may rely on the consent;
		// Disconnect remains the revocation path.
		$connection = new CloudConnection();

		if ( ! empty( $aiPolicy['enabled'] ) && $connection->grantCapability( CloudConnection::CAP_AI ) ) {
			Logger::info( 'Classification consent enabled from the form editor', [ 'form_id' => $formId ] );
		}

		if ( $smsAlert && '' !== $smsPhone && $connection->grantCapability( CloudConnection::CAP_SMS ) ) {
			Logger::info( 'Priority SMS consent enabled from the form editor', [ 'form_id' => $formId ] );
		}

		return [
			'errors'    => [],
			'id'        => $formId,
			'created'   => $created,
			'sms_error' => $smsError,
		];
	}

	/**
	 * Parse posted field rows into ordered definitions.
	 *
	 * @param mixed $posted The relevant_fields POST value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function parseFields( mixed $posted ): array {
		if ( ! is_array( $posted ) ) {
			return [];
		}

		$fields = [];

		foreach ( $posted as $rawField ) {
			if ( ! is_array( $rawField ) ) {
				continue;
			}

			$id   = sanitize_key( (string) ( $rawField['id'] ?? '' ) );
			$type = sanitize_key( (string) ( $rawField['type'] ?? '' ) );

			if ( '' === $id || ! FieldTypeRegistry::has( $type ) ) {
				continue;
			}

			$definition = [
				'id'            => $id,
				'type'          => $type,
				'label'         => sanitize_text_field( (string) ( $rawField['label'] ?? '' ) ),
				'required'      => ! empty( $rawField['required'] ),
				'placeholder'   => sanitize_text_field( (string) ( $rawField['placeholder'] ?? '' ) ),
				'help_text'     => sanitize_text_field( (string) ( $rawField['help_text'] ?? '' ) ),
				'default_value' => sanitize_text_field( (string) ( $rawField['default_value'] ?? '' ) ),
			];

			if ( in_array( $type, [ 'text', 'textarea' ], true ) ) {
				// 0 = no limit.
				$definition['max_length'] = absint( $rawField['max_length'] ?? 0 );
			}

			if ( in_array( $type, [ 'select', 'radio', 'checkbox', 'payment-select', 'payment-multiple', 'payment-checkbox' ], true ) ) {
						$definition['choices'] = self::parseLines( (string) ( $rawField['choices'] ?? '' ) );
			}

			if ( 'payment-single' === $type ) {
				$definition['amount']      = sanitize_text_field( (string) ( $rawField['amount'] ?? '' ) );
				$definition['user_amount'] = ! empty( $rawField['user_amount'] );
				$definition['min_amount']  = sanitize_text_field( (string) ( $rawField['min_amount'] ?? '' ) );
				$definition['max_amount']  = sanitize_text_field( (string) ( $rawField['max_amount'] ?? '' ) );
			}

			if ( 'entry-preview' === $type ) {
				$definition['notice_enable'] = ! empty( $rawField['notice_enable'] );
				$definition['notice']        = sanitize_textarea_field( (string) ( $rawField['notice'] ?? '' ) );
				$previewStyle                = sanitize_key( (string) ( $rawField['style'] ?? '' ) );

				$definition['style'] = in_array( $previewStyle, [ 'basic', 'compact', 'table', 'table_compact' ], true ) ? $previewStyle : 'basic';
			}

			if ( 'content' === $type ) {
				$definition['content'] = wp_kses_post( (string) ( $rawField['content'] ?? '' ) );
			}

			if ( 'html' === $type ) {
				$code = trim( (string) ( $rawField['code'] ?? '' ) );

				// Raw code is storable only by users WordPress trusts with
				// unfiltered HTML; everyone else saves sanitized code, like
				// core does for post content.
				$definition['code'] = current_user_can( 'unfiltered_html' ) ? $code : wp_kses_post( $code );
			}

			if ( 'file' === $type ) {
				$definition['upload_policy'] = [
					'allowed_ext' => self::parseExtensions( (string) ( $rawField['upload_policy']['allowed_ext'] ?? '' ) ),
					'max_size'    => absint( $rawField['upload_policy']['max_size'] ?? 0 ) * 1048576,
					'max_files'   => absint( $rawField['upload_policy']['max_files'] ?? 1 ),
					'scan'        => empty( $rawField['upload_policy']['scan'] ) ? false : true,
					'scan_level'  => 'deep' === (string) ( $rawField['upload_policy']['scan_level'] ?? '' ) ? 'deep' : 'basic',
				];
			}

			$visibility = self::parseVisibility( $rawField['visibility'] ?? null );

			if ( [] !== $visibility ) {
				$definition['visibility'] = $visibility;
			}

			$fields[] = $definition;
		}

		// The first field is always shown: its rules could only depend on
		// fields above it, and there are none — never store any posted for it.
		return VisibilityRules::withoutFirstFieldRules( $fields );
	}

	/**
	 * Parse posted visibility rules into the normalized shape.
	 *
	 * @param mixed $raw The relevant_fields[f_x][visibility] POST value.
	 * @return array<string, mixed> Normalized configuration (empty when inactive).
	 */
	private static function parseVisibility( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$groups = [];

		foreach ( (array) ( $raw['groups'] ?? [] ) as $rawGroup ) {
			if ( ! is_array( $rawGroup ) ) {
				continue;
			}

			$group = [];

			foreach ( $rawGroup as $rawRule ) {
				if ( ! is_array( $rawRule ) ) {
					continue;
				}

				$group[] = [
					'field'    => sanitize_key( (string) ( $rawRule['field'] ?? '' ) ),
					'operator' => sanitize_text_field( (string) ( $rawRule['operator'] ?? '' ) ),
					'value'    => sanitize_text_field( (string) ( $rawRule['value'] ?? '' ) ),
				];
			}

			$groups[] = $group;
		}

		return VisibilityRules::normalize(
			[
				'default' => sanitize_key( (string) ( $raw['default'] ?? '' ) ),
				'groups'  => $groups,
			]
		);
	}

	/**
	 * Split a textarea into trimmed, de-duplicated lines.
	 *
	 * @return array<int, string>
	 */
	private static function parseLines( string $text ): array {
		return array_values( array_unique( TextList::lines( $text ) ) );
	}

	/**
	 * Split an extension list into sanitized, lowercased tokens.
	 *
	 * @return array<int, string>
	 */
	private static function parseExtensions( string $text ): array {
		$extensions = [];

		foreach ( self::parseLines( $text ) as $line ) {
			$token = strtolower( ltrim( trim( $line ), '.' ) );
			$token = preg_replace( '/[^a-z0-9]/', '', $token );

			if ( is_string( $token ) && '' !== $token ) {
				$extensions[] = $token;
			}
		}

		return array_values( array_unique( $extensions ) );
	}
}
