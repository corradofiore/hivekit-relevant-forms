<?php
/**
 * Real-time email deliverability endpoint for form fields.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Http;

use RelevantForms\Support\Ajax;
use RelevantForms\Validation\EmailCheckResult;
use RelevantForms\Validation\EmailDeliverability;

/**
 * Serves the `relevant_forms_email_check` admin-ajax action used by
 * `frontend-email-check.js` while a visitor types an email address.
 *
 * The action is public by design (forms are filled by guests) and is therefore
 * rate limited per client IP. It is never the authority: the submission handler
 * runs the same check server-side, so a blocked or tampered request can only
 * change what the visitor is told, never what is stored.
 */
final class EmailCheckController {

	/**
	 * The admin-ajax action.
	 */
	public const ACTION = 'relevant_forms_email_check';

	/**
	 * Nonce action; the nonce travels in the `nonce` request field.
	 */
	public const NONCE = 'relevant_forms_email_check';

	/**
	 * Request field holding the address.
	 */
	public const PARAM = 'email';

	/**
	 * Checks allowed per client IP inside the window.
	 */
	private const RATE_LIMIT = 40;

	/**
	 * Length of the rate limit window, in seconds.
	 */
	private const RATE_WINDOW = 60;

	/**
	 * How long the nonce of a guest stays valid, in seconds (one day).
	 */
	public const NONCE_LIFETIME = 86400;

	/**
	 * Register the endpoint.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Answer a check request.
	 */
	public static function handle(): void {
		if ( false === check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			Ajax::fail( 'invalid_nonce', __( 'This page is out of date. Please reload it and try again.', 'relevant-forms' ), 403 );
		}

		if ( self::rateLimited() ) {
			Ajax::fail( 'rate_limited', __( 'Too many checks. Please try again in a minute.', 'relevant-forms' ), 429 );
		}

		// The visitor sees a verdict, so a disabled check is a success with an
		// inconclusive payload rather than an error.
		if ( ! EmailDeliverability::enabled() ) {
			wp_send_json_success( EmailCheckResult::unknown()->toArray() );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer() above.
		$email = isset( $_POST[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::PARAM ] ) ) : '';

		if ( ! is_email( $email ) ) {
			Ajax::fail( 'invalid_email', __( 'Please enter a valid email address.', 'relevant-forms' ), 400 );
		}

		wp_send_json_success( EmailDeliverability::check( $email )->toArray() );
	}

	/**
	 * Whether this client IP exhausted its allowance.
	 */
	private static function rateLimited(): bool {
		$key  = 'relevant_forms_email_check_' . md5( self::clientIp() );
		$hits = (int) get_transient( $key );

		if ( $hits >= self::RATE_LIMIT ) {
			return true;
		}

		set_transient( $key, $hits + 1, self::RATE_WINDOW );

		return false;
	}

	/**
	 * Client IP, as far as the request can be trusted.
	 */
	private static function clientIp(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		$ip     = '' === $remote ? false : filter_var( $remote, FILTER_VALIDATE_IP );

		return is_string( $ip ) ? $ip : 'unknown';
	}
}
