<?php
/**
 * Entry-level payment actions (payments doc §4–5): status re-check
 * (diagnostics for pending/failed entries) and full refunds.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Controllers;

use RelevantForms\Entries\EntryRepository;
use RelevantForms\Payments\CloudPaymentsGateway;
use RelevantForms\Payments\PaymentService;

/**
 * Check-status and refund admin-post handlers.
 */
final class PaymentEntryController {

	/**
	 * Register the admin-post actions.
	 */
	public static function register(): void {
		add_action( 'admin_post_relevant_forms_payment_check', [ self::class, 'check' ] );
		add_action( 'admin_post_relevant_forms_payment_refund', [ self::class, 'refund' ] );
	}

	/**
	 * Re-verify a pending/failed payment against the checkout service and
	 * apply the outcome to the entry.
	 */
	public static function check(): void {
		$entryId = self::guard( 'relevant_entry_payment_check_' );

		$service = new PaymentService();
		$entry   = ( new EntryRepository() )->find( $entryId );
		$payment = null !== $entry ? $service->paymentMeta( $entry ) : [];
		$session = (string) ( $payment['session_id'] ?? '' );

		if ( null === $entry || '' === $session ) {
			self::redirect( $entryId, 'no_payment' );
		}

		$gateway = new CloudPaymentsGateway();

		if ( ! $gateway->isAvailable() ) {
			self::redirect( $entryId, 'unavailable' );
		}

		$status = $gateway->sessionStatus( $session );
		$state  = (string) ( $status['status'] ?? '' );

		if ( 'paid' === $state ) {
			$service->markPaid(
				$entryId,
				[
					'session_id'     => $session,
					'payment_intent' => (string) ( $status['payment_intent'] ?? '' ),
					'amount_minor'   => $status['amount_minor'] ?? null,
				]
			);

			self::redirect( $entryId, 'paid' );
		}

		if ( in_array( $state, [ 'expired', 'failed', 'canceled' ], true ) ) {
			$service->markFailed( $entryId, $state );

			self::redirect( $entryId, 'failed' );
		}

		self::redirect( $entryId, 'still_pending' );
	}

	/**
	 * Full refund of a paid entry (v1).
	 */
	public static function refund(): void {
		$entryId = self::guard( 'relevant_entry_payment_refund_' );

		$service = new PaymentService();
		$entry   = ( new EntryRepository() )->find( $entryId );
		$payment = null !== $entry ? $service->paymentMeta( $entry ) : [];
		$session = (string) ( $payment['session_id'] ?? '' );

		if ( null === $entry || '' === $session ) {
			self::redirect( $entryId, 'no_payment' );
		}

		if ( PaymentService::STATUS_PAID !== ( $payment['status'] ?? '' ) ) {
			self::redirect( $entryId, 'not_paid' );
		}

		$gateway = new CloudPaymentsGateway();

		if ( ! $gateway->isAvailable() ) {
			self::redirect( $entryId, 'unavailable' );
		}

		$refund = $gateway->refund( $session );

		if ( null === $refund ) {
			self::redirect( $entryId, 'refund_failed' );
		}

		$service->markRefunded( $entryId, (string) ( $refund['refund_id'] ?? '' ) );

		self::redirect( $entryId, 'refunded' );
	}

	/**
	 * Capability + nonce guard; returns the posted entry id.
	 *
	 * @param string $prefix Nonce action prefix (entry id is appended).
	 */
	private static function guard( string $prefix ): int {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		$entryId = isset( $_POST['entry_id'] ) ? absint( wp_unslash( $_POST['entry_id'] ) ) : 0;

		check_admin_referer( $prefix . $entryId );

		return $entryId;
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
				'page'           => 'relevant-entry',
				'entry_id'       => $entryId,
				'payment_notice' => $notice,
			],
			admin_url( 'admin.php' )
		);

		// Test seam: allows integration tests to intercept the redirect.
		$target = (string) apply_filters( 'relevant_forms_redirect', $target, $entryId );

		wp_safe_redirect( $target );
		exit;
	}
}
