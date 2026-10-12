<?php
/**
 * Visitor notifications for stored entries (email + managed priority SMS).
 *
 * Payment forms defer this until the entry is `paid` — the notifier itself
 * is state-agnostic and always writes delivery events, so both call sites
 * (submission store and payment completion) leave the same trail.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Notifications;

use RelevantForms\Ai\Classification;
use RelevantForms\Alerts\SmsAlert;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Events\EventRecorder;
use RelevantForms\Forms\Form;
use RelevantForms\Support\Logger;

/**
 * Sends the owner-facing notifications for one stored entry.
 */
final class SubmissionNotifier {

	/**
	 * Email the site owner(s) and, when the classification is a priority,
	 * interrupt with the managed SMS.
	 *
	 * Callers gate on spam and payment state; this method assumes the entry
	 * should reach the operator.
	 *
	 * @param Form                  $form           Form entity.
	 * @param int                   $entryId        Stored entry ID.
	 * @param Classification|null   $classification Stored classification, when available.
	 * @param array<string, mixed>  $values         Sanitized field values.
	 */
	public function notify( Form $form, int $entryId, ?Classification $classification, array $values ): void {
		if ( null !== $classification ) {
			$this->notifyPriorityAlert( $form, $entryId, $classification );
		}

		( new EmailNotification() )->send( $form, $values, $entryId, $classification );
	}

	/**
	 * Managed priority SMS (Phase 5, doc §20–22): interrupt the operator
	 * when attention >= threshold. The Cloud renders the message from the
	 * minimal payload (summary + secure entry link); failures only leave
	 * a recorded event — entry and email remain unaffected (doc §57).
	 *
	 * Public because a verdict can arrive after the submission was stored:
	 * a queued classification that succeeds later still owes the operator
	 * the same interrupt.
	 *
	 * @param Form           $form           Form entity.
	 * @param int            $entryId        Stored entry ID.
	 * @param Classification $classification Validated classification.
	 */
	public function notifyPriorityAlert( Form $form, int $entryId, Classification $classification ): void {
		$alert = new SmsAlert();

		if ( ! $alert->shouldSend( $classification, $form ) ) {
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
}
