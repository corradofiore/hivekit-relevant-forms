<?php
/**
 * Replays queued cloud classifications until they succeed.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Ai;

use RelevantForms\Cloud\CloudClient;
use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Cloud\RetryQueue;
use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Entries\StatusResolver;
use RelevantForms\Events\EventRecorder;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Notifications\SubmissionNotifier;
use RelevantForms\Support\Logger;

/**
 * Semantic classification is a paid capability, so a request that fails for
 * a transient reason is queued and replayed until it succeeds — a submission
 * is never left unclassified because the provider hiccupped. The verdict is
 * applied to the entry the moment it arrives: meta, status and the priority
 * alert the operator was promised.
 */
final class ClassificationRetry {

	/**
	 * Cron hook that runs a sweep.
	 */
	public const HOOK = 'relevant_forms_cloud_retry';

	/**
	 * Rows handled in one sweep.
	 */
	private const BATCH = 20;

	/**
	 * Delay before the first sweep after queueing something.
	 */
	private const SCHEDULE_DELAY = MINUTE_IN_SECONDS;

	/**
	 * Longest wait between sweeps: the recurring event is the backstop.
	 */
	private const MAX_SWEEP_DELAY = 15 * MINUTE_IN_SECONDS;

	/**
	 * Queue a failed classification for replay.
	 *
	 * @param int                  $entryId     Entry the verdict belongs to.
	 * @param int                  $formId      Form the entry came from.
	 * @param array<string, mixed> $context     Redacted context that was sent.
	 * @param string               $localStatus Entry status decided without the verdict.
	 * @param string               $error       Why the attempt failed.
	 * @return int Queue row id (0 when it could not be stored).
	 */
	public static function enqueue( int $entryId, int $formId, array $context, string $localStatus, string $error ): int {
		$queue = new RetryQueue();
		$id    = $queue->enqueue( $entryId, $formId, $context, $localStatus, $error );

		if ( 0 === $id ) {
			Logger::error(
				'Classification could not be queued',
				[
					'entry_id' => $entryId,
					'error'    => $error,
				]
			);

			return 0;
		}

		( new EntryRepository() )->updateMeta(
			$entryId,
			'_classification_deferred',
			[
				'state'     => 'queued',
				'error'     => $error,
				'window'    => RetryQueue::window(),
				'queued_at' => current_time( 'mysql', true ),
			]
		);

		EventRecorder::record(
			$entryId,
			'classification_queued',
			[
				'form_id' => $formId,
				'error'   => $error,
			]
		);

		Logger::info(
			'Classification queued for retry',
			[
				'entry_id' => $entryId,
				'queue_id' => $id,
				'error'    => $error,
			]
		);

		self::schedule();

		return $id;
	}

	/**
	 * Make sure a sweep is scheduled soon.
	 *
	 * @param int $delaySeconds Seconds from now.
	 */
	public static function schedule( int $delaySeconds = self::SCHEDULE_DELAY ): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + $delaySeconds, self::HOOK );
		}
	}

	/**
	 * One sweep: give up on rows past their window, replay what is due, and
	 * keep the sweep running while anything is still waiting.
	 */
	public static function run(): void {
		$queue = new RetryQueue();

		foreach ( $queue->expired() as $row ) {
			self::abandon( $queue, $row, (string) ( $row['last_error'] ?? '' ) );
		}

		foreach ( $queue->due( self::BATCH ) as $row ) {
			self::replay( $queue, $row );
		}

		// Re-arm for the moment the next attempt falls due rather than
		// spinning: the recurring sweep stays the safety net.
		$wait = $queue->nextDueIn();

		if ( $wait > 0 ) {
			self::schedule( min( self::MAX_SWEEP_DELAY, max( self::SCHEDULE_DELAY, $wait ) ) );
		}
	}

	/**
	 * Replay one queued classification.
	 *
	 * @param RetryQueue                   $queue Queue store.
	 * @param array<string, mixed>         $row   Queue row.
	 */
	private static function replay( RetryQueue $queue, array $row ): void {
		$id       = (int) ( $row['id'] ?? 0 );
		$attempts = (int) ( $row['attempts'] ?? 0 ) + 1;
		$payload  = json_decode( (string) ( $row['payload'] ?? '' ), true );

		if ( ! is_array( $payload ) || [] === $payload ) {
			self::abandon( $queue, $row, 'payload_unreadable' );

			return;
		}

		$client  = new CloudClient( CloudConfig::apiBase(), CloudConfig::key() );
		$outcome = $client->classifyOutcome( $payload );

		if ( ! $outcome['ok'] || ! is_array( $outcome['data']['classification'] ?? null ) ) {
			$error = (string) $outcome['error'];

			$queue->recordFailure( $id, $error, $attempts );

			// A refusal the cloud will keep repeating (lapsed subscription,
			// revoked key) is not worth the window: settle it now, visibly.
			if ( ! $outcome['retryable'] ) {
				self::abandon( $queue, $row, $error );
			}

			return;
		}

		self::apply( $row, Classification::fromArray( (array) $outcome['data']['classification'] ) );

		$queue->complete( $id, $attempts );
	}

	/**
	 * Apply a late verdict to the entry it belongs to.
	 *
	 * @param array<string, mixed> $row            Queue row.
	 * @param Classification       $classification Verdict.
	 */
	private static function apply( array $row, Classification $classification ): void {
		$entryId = (int) ( $row['entry_id'] ?? 0 );
		$formId  = (int) ( $row['form_id'] ?? 0 );
		$entries = new EntryRepository();

		if ( $entryId > 0 ) {
			$entries->updateMeta( $entryId, '_classification', $classification->toArray() );
			$entries->updateMeta( $entryId, '_attention_score', $classification->attentionScore );
			$entries->deleteMeta( $entryId, '_classification_deferred' );
		}

		$entry  = $entryId > 0 ? $entries->find( $entryId ) : null;
		$status = StatusResolver::resolve( (string) ( $row['local_status'] ?? Entry::STATUS_NEW ), $classification );

		// Only an untouched entry may be settled: an operator who already
		// read or archived it outranks a verdict that arrived later.
		if ( null !== $entry && $status !== $entry->status && StatusResolver::isSettledByVerdict( $entry->status ) ) {
			$entries->updateStatus( $entryId, $status );
		}

		EventRecorder::record(
			$entryId,
			'classification_recovered',
			[
				'attempts'         => (int) ( $row['attempts'] ?? 0 ) + 1,
				'reason_code'      => $classification->reasonCode,
				'attention_score'  => $classification->attentionScore,
				'spam_probability' => $classification->spamProbability,
			]
		);

		Logger::info(
			'Queued classification recovered',
			[
				'entry_id'  => $entryId,
				'attempts'  => (int) ( $row['attempts'] ?? 0 ) + 1,
				'attention' => $classification->attentionScore,
			]
		);

		// The interrupt a priority submission is owed. The e-mail already
		// went out with the submission and is not repeated.
		$form = $formId > 0 ? ( new FormRepository() )->find( $formId ) : null;

		if ( null !== $form && $entryId > 0 ) {
			( new SubmissionNotifier() )->notifyPriorityAlert( $form, $entryId, $classification );
		}

		/**
		 * Fires when a queued classification is finally applied.
		 *
		 * @param int            $entryId        Entry id.
		 * @param Classification $classification Verdict.
		 * @param array<string, mixed> $row      Queue row.
		 */
		do_action( 'relevant_forms_classification_recovered', $entryId, $classification, $row );
	}

	/**
	 * Give up on a row, leaving the entry visibly unclassified rather than
	 * silently so.
	 *
	 * @param RetryQueue           $queue Queue store.
	 * @param array<string, mixed> $row   Queue row.
	 * @param string               $error Reason for giving up.
	 */
	private static function abandon( RetryQueue $queue, array $row, string $error ): void {
		$id      = (int) ( $row['id'] ?? 0 );
		$entryId = (int) ( $row['entry_id'] ?? 0 );
		$error   = '' !== $error ? $error : 'unknown_error';

		$queue->abandon( $id, $error );

		if ( $entryId > 0 ) {
			( new EntryRepository() )->updateMeta(
				$entryId,
				'_classification_deferred',
				[
					'state'     => 'failed',
					'error'     => $error,
					'attempts'  => (int) ( $row['attempts'] ?? 0 ),
					'failed_at' => current_time( 'mysql', true ),
					'queue_id'  => $id,
				]
			);

			EventRecorder::record(
				$entryId,
				'classification_abandoned',
				[
					'attempts' => (int) ( $row['attempts'] ?? 0 ),
					'error'    => $error,
				]
			);
		}

		Logger::error(
			'Classification gave up after its retry window',
			[
				'entry_id' => $entryId,
				'queue_id' => $id,
				'attempts' => (int) ( $row['attempts'] ?? 0 ),
				'error'    => $error,
			]
		);
	}
}
