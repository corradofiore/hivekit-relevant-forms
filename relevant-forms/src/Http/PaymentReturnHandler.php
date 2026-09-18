<?php
/**
 * Browser return leg from the hosted checkout (payments doc §4).
 *
 * The checkout service redirects the visitor back to the page the form was
 * submitted from with `relevant_payment=return|cancel` and the entry id.
 * This handler verifies the session server-side (works without JavaScript),
 * flips the entry state idempotently and redirects to a clean URL carrying
 * the existing success/failure markers.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Http;

use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Payments\CloudPaymentsGateway;
use RelevantForms\Payments\PaymentService;

/**
 * Serves the payment return URLs.
 */
final class PaymentReturnHandler {

	/**
	 * Register the front-end hook. Runs before the renderer so the redirect
	 * always happens first.
	 */
	public static function register(): void {
		add_action( 'template_redirect', [ self::class, 'maybeHandle' ], 5 );
	}

	/**
	 * Handle `?relevant_payment=return|cancel&entry={id}` requests.
	 */
	public static function maybeHandle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing markers set by the checkout service.
		$marker = isset( $_GET['relevant_payment'] ) ? sanitize_key( wp_unslash( $_GET['relevant_payment'] ) ) : '';

		if ( ! in_array( $marker, [ 'return', 'cancel' ], true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing markers set by the checkout service.
		$entryId = isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing markers set by the checkout service.
		$sessionId = isset( $_GET['session'] ) ? sanitize_text_field( wp_unslash( $_GET['session'] ) ) : '';

		$entry = $entryId > 0 ? ( new EntryRepository() )->find( $entryId ) : null;

		if ( null === $entry ) {
			return;
		}

		$form = ( new FormRepository() )->find( $entry->formId );

		if ( null === $form ) {
			return;
		}

		$service = new PaymentService();
		$payment = $service->paymentMeta( $entry );
		$paid    = Entry::STATUS_PAID === $entry->status;

		if ( '' === $sessionId ) {
			$sessionId = (string) ( $payment['session_id'] ?? '' );
		}

		if ( ! $paid && Entry::STATUS_PENDING_PAYMENT === $entry->status && '' !== $sessionId ) {
			$gateway = new CloudPaymentsGateway();

			// The Cloud will normally have flipped the entry through its
			// webhook already; this poll covers the no-JS path and races.
			if ( $gateway->isAvailable() ) {
				$status = $gateway->sessionStatus( $sessionId );
				$state  = (string) ( $status['status'] ?? '' );

				if ( 'paid' === $state ) {
					$service->markPaid(
						$entryId,
						[
							'session_id'     => $sessionId,
							'payment_intent' => (string) ( $status['payment_intent'] ?? '' ),
							'amount_minor'   => $status['amount_minor'] ?? null,
						]
					);
					$paid = true;
				} elseif ( in_array( $state, [ 'expired', 'failed', 'canceled' ], true ) ) {
					$service->markFailed( $entryId, $state );
				}
			}
		}

		self::redirect( $entry, (int) $form->id, $paid );
	}

	/**
	 * Back to the submission page with the appropriate marker and without
	 * the routing query args.
	 *
	 * @param Entry $entry Entry entity.
	 * @param int   $formId Form ID.
	 * @param bool  $paid  Whether the payment completed.
	 */
	private static function redirect( Entry $entry, int $formId, bool $paid ): never {
		$target = wp_validate_redirect( $entry->sourceUrl, home_url( '/' ) );
		$target = remove_query_arg( [ 'relevant_payment', 'entry', 'session' ], $target );

		$target = add_query_arg(
			$paid ? 'relevant_form_success' : 'relevant_payment_failed',
			$formId,
			$target
		);

		$target = (string) apply_filters( 'relevant_forms_payment_return_redirect', $target, $entry->id, $formId, $paid );

		wp_safe_redirect( $target );
		exit;
	}
}
