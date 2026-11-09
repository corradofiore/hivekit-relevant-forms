<?php
/**
 * Domain event recording.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Events;

use RelevantForms\Support\Logger;

/**
 * Records structured events such as notification attempts (doc §49).
 */
final class EventRecorder {

	/**
	 * Record an event.
	 *
	 * @param int|null             $entryId Related entry or null.
	 * @param string               $event   Machine name, e.g. "submission_received".
	 * @param array<string, mixed> $context Structured context.
	 */
	public static function record( ?int $entryId, string $event, array $context = [] ): void {
		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'relevant_events',
			[
				'entry_id'   => $entryId,
				'event'      => $event,
				'context'    => $context !== [] ? wp_json_encode( $context ) : null,
				'created_at' => current_time( 'mysql', true ),
			]
		);

		if ( false === $inserted ) {
			Logger::error(
				'Failed to record event',
				[
					'event'    => $event,
					'entry_id' => $entryId,
				]
			);
		}

		do_action( 'relevant_forms_event_recorded', $entryId, $event, $context );
	}

	/**
	 * Events belonging to an entry, newest first.
	 *
	 * @return array<int, object{id: int, entry_id: int|null, event: string, context: string|null, created_at: string}>
	 */
	public static function forEntry( int $entryId, int $limit = 50 ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT id, entry_id, event, context, created_at FROM ' . $wpdb->prefix . 'relevant_events WHERE entry_id = %d ORDER BY id DESC LIMIT %d',
				$entryId,
				$limit
			)
		);

		return is_array( $rows ) ? $rows : [];
	}
}
