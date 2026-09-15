<?php
/**
 * Entry persistence.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Entries;

/**
 * Repository for wp_sentinel_entries and wp_sentinel_entry_meta.
 */
final class EntryRepository {

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * WordPress database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Create an entry plus its field meta and return the entry ID.
	 *
	 * @param Entry $entry Entry to persist (id is ignored; a new id is assigned).
	 */
	public function create( Entry $entry ): int {
		$table = $this->wpdb->prefix . 'sentinel_entries';
		$now   = current_time( 'mysql', true );

		$this->wpdb->insert(
			$table,
			[
				'form_id'         => $entry->formId,
				'form_version_id' => $entry->formVersionId,
				'status'          => $entry->status,
				'source_url'      => '' !== $entry->sourceUrl ? $entry->sourceUrl : null,
				'referrer'        => '' !== $entry->referrer ? $entry->referrer : null,
				'user_agent_hash' => '' !== $entry->userAgentHash ? $entry->userAgentHash : null,
				'ip_hash'         => '' !== $entry->ipHash ? $entry->ipHash : null,
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);

		$entryId = (int) $this->wpdb->insert_id;

		$this->replaceMeta( $entryId, $entry->meta );

		return $entryId;
	}

	/**
	 * Find an entry with its meta.
	 *
	 * @param int $id Entry ID.
	 */
	public function find( int $id ): ?Entry {
		$table = $this->wpdb->prefix . 'sentinel_entries';

		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal, not user input.

		if ( ! $row instanceof \stdClass ) {
			return null;
		}

		return Entry::fromRow( $row, $this->meta( $id ) );
	}

	/**
	 * List entries, newest first.
	 *
	 * @param array{status?: string, form_id?: int, limit?: int, offset?: int} $args Query args.
	 * @return array<int, Entry>
	 */
	public function findMany( array $args = [] ): array {
		$table = $this->wpdb->prefix . 'sentinel_entries';

		$whereSql = 'WHERE 1 = 1';

		if ( ! empty( $args['status'] ) ) {
			$whereSql .= $this->wpdb->prepare( ' AND status = %s', (string) $args['status'] );
		}

		if ( ! empty( $args['form_id'] ) ) {
			$whereSql .= $this->wpdb->prepare( ' AND form_id = %d', (int) $args['form_id'] );
		}

		$limit  = max( 1, (int) ( $args['limit'] ?? 100 ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$table} {$whereSql} ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name and pre-prepared WHERE clause are internal.

		return array_map( fn ( object $row ): Entry => Entry::fromRow( $row, $this->meta( (int) $row->id ) ), (array) $rows );
	}

	/**
	 * Count entries with the same filters as findMany.
	 *
	 * @param array{status?: string, form_id?: int} $args Query args.
	 */
	public function count( array $args = [] ): int {
		$table = $this->wpdb->prefix . 'sentinel_entries';

		$whereSql = 'WHERE 1 = 1';

		if ( ! empty( $args['status'] ) ) {
			$whereSql .= $this->wpdb->prepare( ' AND status = %s', (string) $args['status'] );
		}

		if ( ! empty( $args['form_id'] ) ) {
			$whereSql .= $this->wpdb->prepare( ' AND form_id = %d', (int) $args['form_id'] );
		}

		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$whereSql}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and pre-prepared WHERE clause are internal.
	}

	/**
	 * Submissions from one IP hash within the given window (doc §12).
	 */
	public function countRecentByIp( string $ipHash, int $windowSeconds ): int {
		$table  = $this->wpdb->prefix . 'sentinel_entries';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $windowSeconds );

		return (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ip_hash = %s AND created_at >= %s", $ipHash, $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal, not user input.
	}

	/**
	 * Identical submission hashes within the given window (doc §12).
	 */
	public function countDuplicates( string $submissionHash, int $windowSeconds ): int {
		$entries = $this->wpdb->prefix . 'sentinel_entries';
		$meta    = $this->wpdb->prefix . 'sentinel_entry_meta';
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $windowSeconds );

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$entries} e INNER JOIN {$meta} m ON m.entry_id = e.id AND m.meta_key = '_submission_hash' AND m.meta_value = %s WHERE e.created_at >= %s",
				$submissionHash,
				$cutoff
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are internal, not user input.
	}

	/**
	 * Update the workflow status.
	 *
	 * @param int    $id     Entry ID.
	 * @param string $status New workflow status.
	 */
	public function updateStatus( int $id, string $status ): bool {
		if ( ! in_array( $status, Entry::STATUSES, true ) ) {
			return false;
		}

		$table = $this->wpdb->prefix . 'sentinel_entries';

		$updated = $this->wpdb->update(
			$table,
			[
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $updated;
	}

	/**
	 * Meta values for an entry, decoded from JSON where needed.
	 *
	 * @param int $entryId Entry ID.
	 * @return array<string, mixed>
	 */
	private function meta( int $entryId ): array {
		$table = $this->wpdb->prefix . 'sentinel_entry_meta';

		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT meta_key, meta_value FROM {$table} WHERE entry_id = %d ORDER BY meta_id ASC", $entryId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal, not user input.

		$meta = [];
		foreach ( (array) $rows as $row ) {
			$value                           = $row->meta_value;
			$decoded                         = json_decode( (string) $value, true );
			$meta[ (string) $row->meta_key ] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
		}

		return $meta;
	}

	/**
	 * Replace all meta rows for an entry.
	 *
	 * @param int                  $entryId Entry ID.
	 * @param array<string, mixed> $meta    Field values keyed by field id.
	 */
	private function replaceMeta( int $entryId, array $meta ): void {
		$table = $this->wpdb->prefix . 'sentinel_entry_meta';

		foreach ( $meta as $key => $value ) {
			$stored = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );

			$this->wpdb->insert(
				$table,
				[
					'entry_id'   => $entryId,
					'meta_key'   => (string) $key,
					'meta_value' => $stored,
				]
			);
		}
	}
}
