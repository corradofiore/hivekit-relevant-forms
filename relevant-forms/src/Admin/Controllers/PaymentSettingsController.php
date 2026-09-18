<?php
/**
 * Stripe Connect settings actions (payments doc §8).
 *
 * Connecting is explicit consent: the admin submits the action, the
 * backend issues a state-bound authorize URL and the browser continues on
 * Stripe. The cloud redirects back here with `relevant_payments=connected`;
 * the capability is only enabled when the backend confirms the connection.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Controllers;

use RelevantForms\Cloud\CloudClient;
use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Cloud\CloudConnection;

/**
 * Connect / refresh / disconnect the payments connection.
 */
final class PaymentSettingsController {

	/**
	 * Register the admin-post actions.
	 */
	public static function register(): void {
		add_action( 'admin_post_relevant_forms_payments_connect', [ self::class, 'connect' ] );
		add_action( 'admin_post_relevant_forms_payments_refresh', [ self::class, 'refresh' ] );
		add_action( 'admin_post_relevant_forms_payments_disconnect', [ self::class, 'disconnect' ] );
		add_action( 'admin_init', [ self::class, 'maybeCompleteReturn' ] );
	}

	/**
	 * Start the OAuth flow: ask the backend for the authorize URL and send
	 * the browser to Stripe.
	 */
	public static function connect(): void {
		self::guard( 'relevant_payments_connect' );

		if ( ! CloudConfig::isConfigured() || CloudConnection::STATE_DISCONNECTED === ( new CloudConnection() )->state() ) {
			self::redirect( 'not_connected' );
		}

		$data      = self::client()->paymentsConnect();
		$authorize = is_array( $data ) ? (string) ( $data['authorize_url'] ?? '' ) : '';
		$scheme    = '' !== $authorize ? wp_parse_url( $authorize, PHP_URL_SCHEME ) : '';

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			self::redirect( 'unavailable' );
		}

		// Stripe-hosted authorization page; off-site by design and the URL
		// was scheme-checked above (created by our cloud for this attempt).
		wp_redirect( $authorize ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated absolute http(s) Stripe URL.
		exit;
	}

	/**
	 * Refresh the stored connection snapshot from the backend.
	 */
	public static function refresh(): void {
		self::guard( 'relevant_payments_refresh' );

		$data = self::client()->paymentsStatus();

		if ( null === $data ) {
			self::redirect( 'unavailable' );
		}

		( new CloudConnection() )->updatePayments( $data );

		self::redirect( ! empty( $data['connected'] ) ? 'connected' : 'not_connected' );
	}

	/**
	 * Revoke the connection at the backend and clear the local consent.
	 */
	public static function disconnect(): void {
		self::guard( 'relevant_payments_disconnect' );

		$connection = new CloudConnection();
		$data       = self::client()->paymentsDisconnect();

		if ( null === $data ) {
			self::redirect( 'unavailable' );
		}

		$connection->updatePayments( $data );
		$connection->disableCapability( CloudConnection::CAP_PAYMENTS );

		self::redirect( 'disconnected' );
	}

	/**
	 * Handle the browser returning from the Stripe authorization page
	 * (`?page=relevant-settings&relevant_payments=connected|error`).
	 *
	 * The capability is enabled only when the backend confirms an active
	 * connection, so a crafted URL can at most trigger one status read.
	 */
	public static function maybeCompleteReturn(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- return marker set by the cloud redirect; consent is re-verified against the backend below.
		if ( ! isset( $_GET['relevant_payments'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'relevant-settings' !== $page || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		$flag = sanitize_key( wp_unslash( $_GET['relevant_payments'] ) );

		if ( 'error' === $flag ) {
			self::redirect( 'error' );
		}

		if ( 'connected' !== $flag ) {
			return;
		}

		$data = self::client()->paymentsStatus();

		if ( null === $data ) {
			self::redirect( 'unavailable' );
		}

		$connection = new CloudConnection();
		$connection->updatePayments( $data );

		if ( empty( $data['connected'] ) ) {
			self::redirect( 'error' );
		}

		$connection->enableCapability( CloudConnection::CAP_PAYMENTS );

		self::redirect( 'connected' );
	}

	/**
	 * Capability + nonce guard for the admin-post actions.
	 *
	 * @param string $action Nonce action name.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( $action );
	}

	/**
	 * Cloud API client for the configured account.
	 */
	private static function client(): CloudClient {
		return new CloudClient( CloudConfig::url(), CloudConfig::key() );
	}

	/**
	 * Redirect back to the settings page with a status marker.
	 *
	 * @param string $status Status marker.
	 */
	private static function redirect( string $status ): never {
		$target = add_query_arg(
			[ 'payments' => $status ],
			admin_url( 'admin.php?page=relevant-settings' )
		);

		// Test seam: allows integration tests to intercept the redirect.
		$target = (string) apply_filters( 'relevant_forms_redirect', $target, 0 );

		wp_safe_redirect( $target );
		exit;
	}
}
