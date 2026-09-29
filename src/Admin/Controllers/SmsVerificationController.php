<?php
/**
 * SMS verification for the per-form priority recipient (admin-ajax).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Controllers;

use RelevantForms\Alerts\PhoneNumber;
use RelevantForms\Cloud\CloudClient;
use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Cloud\CloudConnection;

/**
 * Sends and verifies the 6-digit one-time code for the phone number used
 * by the form editor's priority-SMS option.
 *
 * A number is only stored in the form schema after the Cloud confirms
 * the code: this controller records the local proof (a transient keyed
 * to user + number) that FormSaveController requires. No Cloud request
 * is made without the priority-SMS capability consent (v2 §7–8).
 */
final class SmsVerificationController {

	/**
	 * AJAX action (also the nonce action) for issuing a code.
	 */
	public const ACTION_SEND = 'relevant_forms_sms_send';

	/**
	 * AJAX action (also the nonce action) for redeeming a code.
	 */
	public const ACTION_VERIFY = 'relevant_forms_sms_verify';

	/**
	 * How long a verified number stays saveable.
	 */
	private const VERIFIED_TTL = HOUR_IN_SECONDS;

	/**
	 * Register the AJAX handlers.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION_SEND, [ self::class, 'send' ] );
		add_action( 'wp_ajax_' . self::ACTION_VERIFY, [ self::class, 'verify' ] );
	}

	/**
	 * Whether the current user verified this number recently — the save
	 * gate for a new or changed number.
	 *
	 * @param string $phone Normalized E.164 number.
	 */
	public static function isVerified( string $phone ): bool {
		return '' !== (string) get_transient( self::verifiedKey( $phone ) );
	}

	/**
	 * AJAX: issue a 6-digit code to the submitted number.
	 */
	public static function send(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to verify phone numbers.', 'relevant-forms' ) ], 403 );
		}

		check_ajax_referer( self::ACTION_SEND );

		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['phone'] ) ) : '';
		$phone = PhoneNumber::normalize( $phone );

		if ( null === $phone ) {
			wp_send_json_error(
				[
					'code'    => 'invalid_phone',
					'message' => __( 'Use an international number with the country code, e.g. +39 333 123 4567.', 'relevant-forms' ),
				],
				400
			);
		}

		if ( ! CloudConfig::isConfigured() || ! ( new CloudConnection() )->capabilityEnabled( CloudConnection::CAP_SMS ) ) {
			wp_send_json_error(
				[
					'code'    => 'capability_disabled',
					'message' => __( 'Enable priority SMS in Relevant Forms → Settings first.', 'relevant-forms' ),
				],
				400
			);
		}

		$client = new CloudClient( CloudConfig::apiBase(), CloudConfig::key() );
		$result = $client->requestSmsVerification( $phone );
		$data   = $result['data'];

		if ( null === $data || ! isset( $data['verification_id'] ) ) {
			wp_send_json_error(
				[
					'code'    => self::errorCode( $result ),
					'message' => self::errorMessage( $result ),
				],
				502
			);
		}

		$normalized = (string) $data['phone'];

		set_transient( self::pendingKey( $normalized ), (int) $data['verification_id'], 10 * MINUTE_IN_SECONDS );

		wp_send_json_success(
			[
				'phone'     => $normalized,
				'delivered' => ! empty( $data['delivered'] ),
				'dev_code'  => isset( $data['dev_code'] ) && is_string( $data['dev_code'] ) && '' !== $data['dev_code'] ? $data['dev_code'] : null,
			]
		);
	}

	/**
	 * AJAX: check the 6-digit code and mark the number verified.
	 */
	public static function verify(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to verify phone numbers.', 'relevant-forms' ) ], 403 );
		}

		check_ajax_referer( self::ACTION_VERIFY );

		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['phone'] ) ) : '';
		$phone = PhoneNumber::normalize( $phone );
		$code  = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['code'] ) ) : '';
		$code  = trim( $code );

		if ( null === $phone ) {
			wp_send_json_error(
				[
					'code'    => 'invalid_phone',
					'message' => __( 'Use an international number with the country code, e.g. +39 333 123 4567.', 'relevant-forms' ),
				],
				400
			);
		}

		if ( 1 !== preg_match( '/^[0-9]{6}$/', $code ) ) {
			wp_send_json_error(
				[
					'code'    => 'invalid_code',
					'message' => __( 'Enter the 6-digit code we texted you.', 'relevant-forms' ),
				],
				400
			);
		}

		$verificationId = (int) get_transient( self::pendingKey( $phone ) );

		if ( $verificationId <= 0 ) {
			wp_send_json_error(
				[
					'code'    => 'no_pending_code',
					'message' => __( 'Send a code first.', 'relevant-forms' ),
				],
				400
			);
		}

		$client = new CloudClient( CloudConfig::apiBase(), CloudConfig::key() );
		$result = $client->verifySmsCode( $verificationId, $code );

		if ( empty( $result['data']['verified'] ) ) {
			wp_send_json_error(
				[
					'code'    => self::errorCode( $result ),
					'message' => self::errorMessage( $result ),
				],
				502
			);
		}

		$verified = isset( $result['data']['phone'] ) ? (string) $result['data']['phone'] : $phone;

		delete_transient( self::pendingKey( $phone ) );
		set_transient( self::verifiedKey( $verified ), $verified, self::VERIFIED_TTL );

		wp_send_json_success(
			[
				'phone'    => $verified,
				'verified' => true,
			]
		);
	}

	/**
	 * Transient key tracking the open code for a number (per user).
	 *
	 * @param string $phone Normalized E.164 number.
	 */
	private static function pendingKey( string $phone ): string {
		return 'relevant_forms_sms_pending_' . get_current_user_id() . '_' . md5( $phone );
	}

	/**
	 * Transient key proving a number passed the OTP check (per user).
	 *
	 * @param string $phone Normalized E.164 number.
	 */
	private static function verifiedKey( string $phone ): string {
		return 'relevant_forms_sms_verified_' . get_current_user_id() . '_' . md5( $phone );
	}

	/**
	 * Stable error code from a Cloud result (or network_error).
	 *
	 * @param array{status: int, data: array<string, mixed>|null, error: array<string, mixed>|null} $result Cloud result.
	 */
	private static function errorCode( array $result ): string {
		if ( 0 === $result['status'] ) {
			return 'network_error';
		}

		return (string) ( $result['error']['code'] ?? 'request_failed' );
	}

	/**
	 * Human-readable message for a Cloud error result.
	 *
	 * @param array{status: int, data: array<string, mixed>|null, error: array<string, mixed>|null} $result Cloud result.
	 */
	private static function errorMessage( array $result ): string {
		$messages = [
			'invalid_phone'          => __( 'Use an international number with the country code, e.g. +39 333 123 4567.', 'relevant-forms' ),
			'rate_limited'           => __( 'Too many codes requested. Wait a few minutes and try again.', 'relevant-forms' ),
			'capability_disabled'    => __( 'Priority SMS is not enabled for this deployment.', 'relevant-forms' ),
			'network_error'          => __( 'Could not reach Relevant Cloud. Try again.', 'relevant-forms' ),
			'request_failed'         => __( 'The request failed. Try again.', 'relevant-forms' ),
			'invalid_code'           => __( 'The code is incorrect.', 'relevant-forms' ),
			'code_expired'           => __( 'The code expired. Send a new one.', 'relevant-forms' ),
			'verification_consumed'  => __( 'That code was already used. Send a new one.', 'relevant-forms' ),
			'verification_not_found' => __( 'Send a code first.', 'relevant-forms' ),
			'too_many_attempts'      => __( 'Too many attempts. Send a new code.', 'relevant-forms' ),
			'site_not_found'         => __( 'The site is not connected to Relevant Cloud.', 'relevant-forms' ),
		];

		$code = self::errorCode( $result );

		if ( isset( $messages[ $code ] ) ) {
			return $messages[ $code ];
		}

		$message = (string) ( $result['error']['message'] ?? '' );

		return '' !== $message ? $message : __( 'The request failed. Try again.', 'relevant-forms' );
	}
}
