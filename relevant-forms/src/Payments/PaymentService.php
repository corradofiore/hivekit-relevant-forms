<?php
/**
 * Payment state machine for stored entries (payments doc §4).
 *
 * The entry status and the `_payment` meta row move together:
 *
 *   pending_payment ─► paid ─► refunded
 *          └─────────► payment_failed
 *
 * All transitions are idempotent and event-recorded; the Cloud (or the
 * return handler) drives them, never the visitor's browser directly.
 * Notifications are deferred from submission to `paid`.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Payments;

use RelevantForms\Ai\Classification;
use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Events\EventRecorder;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Notifications\SubmissionNotifier;

/**
 * Stores and flips payment state on entries.
 */
final class PaymentService {

	/**
	 * Entry meta key holding the payment record.
	 */
	public const META_KEY = '_payment';

	/**
	 * Meta-level payment statuses (distinct from entry workflow statuses).
	 */
	public const STATUS_PENDING  = 'pending';
	public const STATUS_PAID     = 'paid';
	public const STATUS_FAILED   = 'failed';
	public const STATUS_REFUNDED = 'refunded';

	/**
	 * The stored payment record of an entry ([] when none).
	 *
	 * @param Entry $entry Entry entity.
	 * @return array<string, mixed>
	 */
	public function paymentMeta( Entry $entry ): array {
		$payment = $entry->meta[ self::META_KEY ] ?? null;

		return is_array( $payment ) ? $payment : [];
	}

	/**
	 * Record the pending payment (server-computed breakdown) after the
	 * entry has been stored.
	 *
	 * @param int                                                                                                                   $entryId   Stored entry ID.
	 * @param array{currency: string, total_minor: int, items: array<int, array{field_id: string, label: string, detail: string, amount_minor: int}>} $breakdown PaymentTotals::breakdown().
	 */
	public function recordPending( int $entryId, array $breakdown ): void {
		$payment               = $breakdown;
		$payment['status']     = self::STATUS_PENDING;
		$payment['created_at'] = current_time( 'mysql', true );

		( new EntryRepository() )->updateMeta( $entryId, self::META_KEY, $payment );

		EventRecorder::record(
			$entryId,
			'payment_pending',
			[
				'currency'    => $payment['currency'],
				'total_minor' => $payment['total_minor'],
			]
		);
	}

	/**
	 * Store the checkout session reference on the pending record.
	 *
	 * @param int                  $entryId Stored entry ID.
	 * @param array<string, mixed> $session Checkout session data (id, checkout_url).
	 */
	public function recordSession( int $entryId, array $session ): void {
		$repository = new EntryRepository();
		$entry      = $repository->find( $entryId );

		if ( null === $entry || self::STATUS_PENDING !== ( $this->paymentMeta( $entry )['status'] ?? '' ) ) {
			return;
		}

		$payment                    = $this->paymentMeta( $entry );
		$payment['session_id']      = (string) ( $session['id'] ?? '' );
		$payment['checkout_url']    = (string) ( $session['checkout_url'] ?? '' );
		$payment['session_created'] = current_time( 'mysql', true );

		$repository->updateMeta( $entryId, self::META_KEY, $payment );

		EventRecorder::record( $entryId, 'payment_session_created', [ 'session_id' => $payment['session_id'] ] );
	}

	/**
	 * Flip a pending (or failed) entry to `paid`. Fires the deferred
	 * notifications exactly once (idempotent by status guard).
	 *
	 * @param int                  $entryId Stored entry ID.
	 * @param array<string, mixed> $context Webhook/return data: session_id, payment_intent, amount_minor.
	 */
	public function markPaid( int $entryId, array $context = [] ): bool {
		$repository = new EntryRepository();
		$entry      = $repository->find( $entryId );

		if ( null === $entry || ! in_array( $entry->status, [ Entry::STATUS_PENDING_PAYMENT, Entry::STATUS_PAYMENT_FAILED ], true ) ) {
			return false;
		}

		$payment           = $this->paymentMeta( $entry );
		$payment['status'] = self::STATUS_PAID;

		$payment['paid_at'] = current_time( 'mysql', true );

		if ( isset( $context['amount_minor'] ) ) {
			$payment['amount_received_minor'] = (int) $context['amount_minor'];
		}

		foreach ( [ 'session_id', 'payment_intent' ] as $key ) {
			if ( '' !== (string) ( $context[ $key ] ?? '' ) ) {
				$payment[ $key ] = (string) $context[ $key ];
			}
		}

		$repository->updateMeta( $entryId, self::META_KEY, $payment );
		$repository->updateStatus( $entryId, Entry::STATUS_PAID );

		EventRecorder::record(
			$entryId,
			'payment_paid',
			[
				'currency'    => (string) ( $payment['currency'] ?? '' ),
				'total_minor' => (int) ( $payment['total_minor'] ?? 0 ),
			]
		);

		do_action( 'relevant_forms_payment_paid', $entryId, $entry->formId );

		$this->notifyAfterPaid( $entry );

		return true;
	}

	/**
	 * Flip a pending entry to `payment_failed` (expired/failed session).
	 *
	 * @param int    $entryId Stored entry ID.
	 * @param string $reason  Machine-ish reason for the ledger.
	 */
	public function markFailed( int $entryId, string $reason = '' ): bool {
		$repository = new EntryRepository();
		$entry      = $repository->find( $entryId );

		if ( null === $entry || Entry::STATUS_PENDING_PAYMENT !== $entry->status ) {
			return false;
		}

		$reason = substr( sanitize_text_field( $reason ), 0, 120 );

		$payment              = $this->paymentMeta( $entry );
		$payment['status']    = self::STATUS_FAILED;
		$payment['failed_at'] = current_time( 'mysql', true );
		$payment['reason']    = $reason;

		$repository->updateMeta( $entryId, self::META_KEY, $payment );
		$repository->updateStatus( $entryId, Entry::STATUS_PAYMENT_FAILED );

		EventRecorder::record( $entryId, 'payment_failed', [ 'reason' => $reason ] );

		return true;
	}

	/**
	 * Flip a paid entry to `refunded` (full refund v1).
	 *
	 * @param int    $entryId  Stored entry ID.
	 * @param string $refundId Refund reference, when known.
	 */
	public function markRefunded( int $entryId, string $refundId = '' ): bool {
		$repository = new EntryRepository();
		$entry      = $repository->find( $entryId );

		if ( null === $entry || Entry::STATUS_PAID !== $entry->status ) {
			return false;
		}

		$payment                = $this->paymentMeta( $entry );
		$payment['status']      = self::STATUS_REFUNDED;
		$payment['refunded_at'] = current_time( 'mysql', true );

		if ( '' !== $refundId ) {
			$payment['refund_id'] = substr( sanitize_text_field( $refundId ), 0, 120 );
		}

		$repository->updateMeta( $entryId, self::META_KEY, $payment );
		$repository->updateStatus( $entryId, Entry::STATUS_REFUNDED );

		EventRecorder::record( $entryId, 'payment_refunded', [ 'refund_id' => $refundId ] );

		do_action( 'relevant_forms_payment_refunded', $entryId, $entry->formId );

		return true;
	}

	/**
	 * Deferred notifications: the entry only reaches the operator here,
	 * after the payment went through. Uses the classification stored at
	 * submission time (payments doc §4).
	 *
	 * @param Entry $entry Paid entry (with meta).
	 */
	private function notifyAfterPaid( Entry $entry ): void {
		if ( null === $entry->id ) {
			return;
		}

		$form = ( new FormRepository() )->find( $entry->formId );

		if ( null === $form ) {
			return;
		}

		$values = array_filter(
			$entry->meta,
			static fn ( mixed $key ): bool => ! is_string( $key ) || ! str_starts_with( $key, '_' ),
			ARRAY_FILTER_USE_KEY
		);

		$classification = null;
		$stored         = $entry->meta['_classification'] ?? null;

		if ( is_array( $stored ) ) {
			$classification = Classification::fromArray( $stored );
		}

		( new SubmissionNotifier() )->notify( $form, (int) $entry->id, $classification, $values );
	}
}
