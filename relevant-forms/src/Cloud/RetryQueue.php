<?php
/**
 * Durable queue for cloud work that may be retried.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Cloud;

use RelevantForms\Support\Logger;

/**
 * Stores cloud requests whose first attempt failed for a transient reason
 * and replays them until they succeed or their window closes. Rows carry
 * the entry they belong to, so the outcome can be applied to the entry the
 * moment it arrives — a paid capability is never dropped silently.
 */
final class RetryQueue {

	/**
	 * Kind: semantic classification of a stored entry.
	 */
	public const KIND_CLASSIFICATION = 'classification';

	/**
	 * Status: waiting for its next attempt.
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Status: the work completed.
	 */
	public const STATUS_DONE = 'done';

	/**
	 * Status: the window closed without success.
	 */
	public const STATUS_ABANDONED = 'abandoned';

	/**
	 * Seconds a row may keep being retried before it is given up on.
	 */
	public const DEFAULT_WINDOW = 8 * HOUR_IN_SECONDS;

	/**
	 * Delay before the first retry.
	 */
	private const FIRST_DELAY = MINUTE_IN_SECONDS;

	/**
	 * Ceiling for the exponential backoff between retries.
	 */
	private const MAX_DELAY = HOUR_IN_SECONDS;

	/**
	 * Table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;

		$this->table = $wpdb->prefix . 'relevant_cloud_queue';
	}

	/**
	 * How long a queued request may keep being retried.
	 */
	public static function window(): int {
		/**
		 * Filter the retry window for queued cloud work.
		 *
		 * @param int $seconds Window in seconds.
		 */
		$window = (int) apply_filters( 'relevant_forms_cloud_retry_window', self::DEFAULT_WINDOW );

		return max( MINUTE_IN_SECONDS, $window );
	}

	/**
	 * Queue one request for later replay.
	 *
	 * @param int                  $entryId     Entry the work belongs to.
	 * @param int                  $formId      Form the entry came from.
	 * @param array<string, mixed> $payload     Request body to replay.
	 * @param string               $localStatus Entry status decided without the cloud result.
	 * @param string               $error       Why the first attempt failed.
	 * @param string               $kind        Work kind.
	 * @return int Row id, or 0 when it could not be stored.
	 */
	public function enqueue( int $entryId, int $formId, array $payload, string $localStatus, string $error = '', string $kind = self::KIND_CLASSIFICATION ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned queue table.
		$inserted = $wpdb->insert(
			$this->table,
			[
				'entry_id'        => $entryId,
				'form_id'         => $formId,
				'kind'            => $kind,
				'local_status'    => $localStatus,
				'payload'         => (string) wp_json_encode( $payload ),
				'attempts'        => 0,
				'last_error'      => mb_substr( $error, 0, 255 ),
				'status'          => self::STATUS_PENDING,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + self::FIRST_DELAY ),
				'expires_at'      => gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + self::window() ),
				'created_at'      => $now,
			]
		);

		if ( false === $inserted ) {
			Logger::error(
				'Could not queue the cloud request',
				[
					'entry_id' => $entryId,
					'kind'     => $kind,
				]
			);

			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Rows whose next attempt is due.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function due( int $limit = 20 ): array {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned queue table, values bound below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status = %s AND next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT %d",
				self::STATUS_PENDING,
				$now,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Pending rows whose window has closed.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function expired( int $limit = 50 ): array {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned queue table, values bound below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status = %s AND expires_at <= %s ORDER BY expires_at ASC LIMIT %d",
				self::STATUS_PENDING,
				$now,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Seconds until the earliest pending attempt, or 0 when nothing waits.
	 */
	public function nextDueIn(): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned queue table, value bound below.
		$next = $wpdb->get_var(
			$wpdb->prepare( "SELECT MIN(next_attempt_at) FROM {$this->table} WHERE status = %s", self::STATUS_PENDING )
		);

		if ( ! is_string( $next ) || '' === $next ) {
			return 0;
		}

		return max( 0, (int) strtotime( $next . ' UTC' ) - (int) strtotime( $now . ' UTC' ) );
	}

	/**
	 * Number of rows still waiting.
	 */
	public function pendingCount(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned queue table, value bound below.
		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE status = %s", self::STATUS_PENDING )
		);

		return (int) $count;
	}

	/**
	 * Record a failed attempt and schedule the next one.
	 *
	 * @param int    $id        Row id.
	 * @param string $error     Last error, for the operator.
	 * @param int    $attempts  Attempts so far.
	 */
	public function recordFailure( int $id, string $error, int $attempts ): void {
		global $wpdb;

		$now   = current_time( 'mysql', true );
		$delay = min( self::MAX_DELAY, self::FIRST_DELAY * ( 2 ** max( 1, $attempts ) ) );
		$next  = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + $delay );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned queue table.
		$wpdb->update(
			$this->table,
			[
				'attempts'        => $attempts,
				'last_error'      => mb_substr( $error, 0, 255 ),
				'next_attempt_at' => $next,
			],
			[ 'id' => $id ]
		);
	}

	/**
	 * Mark a row as completed, recording how many attempts it took.
	 *
	 * @param int $id       Row id.
	 * @param int $attempts Attempts made, including the successful one.
	 */
	public function complete( int $id, int $attempts = 0 ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned queue table.
		$wpdb->update(
			$this->table,
			[
				'status'       => self::STATUS_DONE,
				'attempts'     => max( 0, $attempts ),
				'completed_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $id ]
		);
	}

	/**
	 * Give up on a row whose window closed.
	 *
	 * @param int    $id    Row id.
	 * @param string $error Last error, for the operator.
	 */
	public function abandon( int $id, string $error ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned queue table.
		$wpdb->update(
			$this->table,
			[
				'status'       => self::STATUS_ABANDONED,
				'last_error'   => mb_substr( $error, 0, 255 ),
				'completed_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $id ]
		);
	}

	/**
	 * Rows belonging to one entry, newest first.
	 *
	 * @param int $entryId Entry id.
	 * @return array<int, array<string, mixed>>
	 */
	public function forEntry( int $entryId ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned queue table, value bound below.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE entry_id = %d ORDER BY id DESC", $entryId ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Delete completed rows created before the cutoff.
	 *
	 * @param string $cutoff MySQL datetime (UTC).
	 * @return int Rows deleted.
	 */
	public function prune( string $cutoff ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned queue table, values bound below.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE status IN (%s, %s) AND completed_at IS NOT NULL AND completed_at < %s",
				self::STATUS_DONE,
				self::STATUS_ABANDONED,
				$cutoff
			)
		);

		return (int) $deleted;
	}
}
