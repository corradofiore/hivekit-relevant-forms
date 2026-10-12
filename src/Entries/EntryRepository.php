<?php
/**
 * Entry persistence.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Entries;

/**
 * Repository for wp_relevant_entries and wp_relevant_entry_meta.
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
		$table = $this->wpdb->prefix . 'relevant_entries';
		$now   = current_time( 'mysql', true );

		$result = $this->wpdb->insert(
			$table,
			[
				'form_id'           => $entry->formId,
				'form_version_id'   => $entry->formVersionId,
				'status'            => $entry->status,
				'source_url'        => '' !== $entry->sourceUrl ? $entry->sourceUrl : null,
				'referrer'          => '' !== $entry->referrer ? $entry->referrer : null,
				'user_agent_hash'   => '' !== $entry->userAgentHash ? $entry->userAgentHash : null,
				'ip_hash'           => '' !== $entry->ipHash ? $entry->ipHash : null,
				'resume_token_hash' => $entry->resumeTokenHash,
				'resume_email'      => $entry->resumeEmail,
				'resume_expires_at' => $entry->resumeExpiresAt,
				'resume_page'       => $entry->resumePage,
				'created_at'        => $now,
				'updated_at'        => $now,
			]
		);

		$entryId = (int) $this->wpdb->insert_id;

		$this->assertInserted( $result, $entryId, $table );

		$this->replaceMeta( $entryId, $entry->meta );

		return $entryId;
	}

	/**
	 * Find an entry with its meta.
	 *
	 * @param int $id Entry ID.
	 */
	public function find( int $id ): ?Entry {
		$wpdb = $this->wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'relevant_entries WHERE id = %d', $id ) );

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
		$wpdb = $this->wpdb;

		$status = (string) ( $args['status'] ?? '' );
		$formId = (int) ( $args['form_id'] ?? 0 );
		$limit  = max( 1, (int) ( $args['limit'] ?? 100 ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		if ( '' !== $status && $formId > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'relevant_entries WHERE status = %s AND form_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d', $status, $formId, $limit, $offset )
			);
		} elseif ( '' !== $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'relevant_entries WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d', $status, $limit, $offset )
			);
		} elseif ( $formId > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'relevant_entries WHERE form_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d', $formId, $limit, $offset )
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'relevant_entries ORDER BY created_at DESC LIMIT %d OFFSET %d', $limit, $offset )
			);
		}

		return array_map( fn ( object $row ): Entry => Entry::fromRow( $row, $this->meta( (int) $row->id ) ), (array) $rows );
	}

	/**
	 * Count entries with the same filters as findMany.
	 *
	 * @param array{status?: string, form_id?: int} $args Query args.
	 */
	public function count( array $args = [] ): int {
		$wpdb = $this->wpdb;

		$status = (string) ( $args['status'] ?? '' );
		$formId = (int) ( $args['form_id'] ?? 0 );

		if ( '' !== $status && $formId > 0 ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'relevant_entries WHERE status = %s AND form_id = %d', $status, $formId ) );
		}

		if ( '' !== $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'relevant_entries WHERE status = %s', $status ) );
		}

		if ( $formId > 0 ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'relevant_entries WHERE form_id = %d', $formId ) );
		}

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'relevant_entries' );
	}

	/**
	 * Submissions from one IP hash within the given window (doc §12).
	 */
	public function countRecentByIp( string $ipHash, int $windowSeconds ): int {
		$wpdb   = $this->wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $windowSeconds );

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'relevant_entries WHERE ip_hash = %s AND created_at >= %s', $ipHash, $cutoff ) );
	}

	/**
	 * Identical submission hashes within the given window (doc §12).
	 */
	public function countDuplicates( string $submissionHash, int $windowSeconds ): int {
		$wpdb   = $this->wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $windowSeconds );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'relevant_entries e INNER JOIN ' . $wpdb->prefix . 'relevant_entry_meta m ON m.entry_id = e.id AND m.meta_key = %s AND m.meta_value = %s WHERE e.created_at >= %s',
				'_submission_hash',
				$submissionHash,
				$cutoff
			)
		);
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

		$table = $this->wpdb->prefix . 'relevant_entries';

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
	 * Insert or replace a single meta value.
	 *
	 * @param int    $entryId Entry ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value (arrays are stored as JSON).
	 */
	public function updateMeta( int $entryId, string $key, mixed $value ): bool {
		$table = $this->wpdb->prefix . 'relevant_entry_meta';

		$this->wpdb->delete(
			$table,
			[
				'entry_id' => $entryId,
				'meta_key' => $key,
			],
			[ '%d', '%s' ]
		);

		$stored = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );

		$inserted = $this->wpdb->insert(
			$table,
			[
				'entry_id'   => $entryId,
				'meta_key'   => $key,
				'meta_value' => $stored,
			]
		);

		return false !== $inserted;
	}

	/**
	 * Remove a single meta value.
	 *
	 * @param int    $entryId Entry ID.
	 * @param string $key     Meta key.
	 */
	public function deleteMeta( int $entryId, string $key ): bool {
		$table = $this->wpdb->prefix . 'relevant_entry_meta';

		$deleted = $this->wpdb->delete(
			$table,
			[
				'entry_id' => $entryId,
				'meta_key' => $key,
			],
			[ '%d', '%s' ]
		);

		return false !== $deleted;
	}

	/**
	 * Update a partial entry in place: resume state and saved values.
	 *
	 * @param Entry $entry Entry with id set; resume fields carry the new state.
	 */
	public function updatePartial( Entry $entry ): bool {
		if ( null === $entry->id ) {
			return false;
		}

		$table = $this->wpdb->prefix . 'relevant_entries';

		$updated = $this->wpdb->update(
			$table,
			[
				'resume_token_hash' => $entry->resumeTokenHash,
				'resume_email'      => $entry->resumeEmail,
				'resume_expires_at' => $entry->resumeExpiresAt,
				'resume_page'       => $entry->resumePage,
				'source_url'        => '' !== $entry->sourceUrl ? $entry->sourceUrl : null,
				'updated_at'        => current_time( 'mysql', true ),
			],
			[ 'id' => $entry->id ]
		);

		if ( false === $updated ) {
			return false;
		}

		$this->replaceMeta( (int) $entry->id, $entry->meta );

		return true;
	}

	/**
	 * Convert a partial entry into the final submission: replace its values,
	 * set the workflow status, refresh the schema version and clear the
	 * resume state. The original created_at is kept.
	 *
	 * @param int                  $id        Entry ID.
	 * @param int                  $versionId Schema version snapshot ID.
	 * @param string               $status    Final workflow status.
	 * @param array<string, mixed> $meta      Final meta (values plus markers).
	 * @param string               $sourceUrl Page the submission came from.
	 * @param string               $referrer  HTTP referer.
	 */
	public function completePartial( int $id, int $versionId, string $status, array $meta, string $sourceUrl = '', string $referrer = '' ): bool {
		$table = $this->wpdb->prefix . 'relevant_entries';

		$updated = $this->wpdb->update(
			$table,
			[
				'form_version_id'      => $versionId,
				'status'               => $status,
				'source_url'           => '' !== $sourceUrl ? $sourceUrl : null,
				'referrer'             => '' !== $referrer ? $referrer : null,
				'resume_token_hash'    => null,
				'resume_email'         => null,
				'resume_expires_at'    => null,
				'resume_page'          => null,
				'resume_email_sent_at' => null,
				'updated_at'           => current_time( 'mysql', true ),
			],
			[ 'id' => $id ]
		);

		if ( false === $updated ) {
			return false;
		}

		$this->replaceMeta( $id, $meta );

		return true;
	}

	/**
	 * Find the partial entry behind a resume token hash.
	 *
	 * @param string $hash SHA-256 token hash.
	 */
	public function findPartialByResumeHash( string $hash ): ?Entry {
		$wpdb = $this->wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'relevant_entries WHERE resume_token_hash = %s AND status = %s LIMIT 1',
				$hash,
				Entry::STATUS_PARTIAL
			)
		);

		if ( ! $row instanceof \stdClass ) {
			return null;
		}

		return Entry::fromRow( $row, $this->meta( (int) $row->id ) );
	}

	/**
	 * Newest active partial for a form + email pair (save de-duplication).
	 *
	 * @param int    $formId Form ID.
	 * @param string $email  Requested email address.
	 */
	public function findPartialByEmail( int $formId, string $email ): ?Entry {
		$wpdb = $this->wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'relevant_entries WHERE form_id = %d AND resume_email = %s AND status = %s ORDER BY id DESC LIMIT 1',
				$formId,
				$email,
				Entry::STATUS_PARTIAL
			)
		);

		if ( ! $row instanceof \stdClass ) {
			return null;
		}

		return Entry::fromRow( $row, $this->meta( (int) $row->id ) );
	}

	/**
	 * Record when the resume link was last emailed.
	 *
	 * @param int $id Entry ID.
	 */
	public function markResumeEmailSent( int $id ): void {
		$this->wpdb->update(
			$this->wpdb->prefix . 'relevant_entries',
			[
				'resume_email_sent_at' => current_time( 'mysql', true ),
				'updated_at'           => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Delete partial entries whose resume link expired (cron cleanup).
	 *
	 * @param string $now UTC datetime cutoff.
	 * @return int Rows deleted.
	 */
	public function pruneExpiredPartials( string $now ): int {
		$wpdb      = $this->wpdb;
		$table     = $wpdb->prefix . 'relevant_entries';
		$metaTable = $wpdb->prefix . 'relevant_entry_meta';

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . $table . ' WHERE status = %s AND resume_expires_at IS NOT NULL AND resume_expires_at < %s',
				Entry::STATUS_PARTIAL,
				$now
			)
		);

		$ids = array_map( 'intval', (array) $ids );

		foreach ( $ids as $id ) {
			$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
			$wpdb->delete( $metaTable, [ 'entry_id' => $id ], [ '%d' ] );
		}

		return count( $ids );
	}

	/**
	 * Meta values for an entry, decoded from JSON where needed.
	 *
	 * @param int $entryId Entry ID.
	 * @return array<string, mixed>
	 */
	private function meta( int $entryId ): array {
		$wpdb = $this->wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, meta_value FROM ' . $wpdb->prefix . 'relevant_entry_meta WHERE entry_id = %d ORDER BY meta_id ASC', $entryId ) );

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
		$table = $this->wpdb->prefix . 'relevant_entry_meta';

		$this->wpdb->delete( $table, [ 'entry_id' => $entryId ], [ '%d' ] );

		foreach ( $meta as $key => $value ) {
			$stored = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );

			$result = $this->wpdb->insert(
				$table,
				[
					'entry_id'   => $entryId,
					'meta_key'   => (string) $key,
					'meta_value' => $stored,
				]
			);

			$this->assertInserted( $result, (int) $this->wpdb->insert_id, $table );
		}
	}

	/**
	 * Whether a `$wpdb::insert()` call really wrote a row.
	 *
	 * `insert()` returns false on failure and leaves `insert_id` at whatever the
	 * previous query left there, so the id alone cannot be trusted either.
	 *
	 * @param mixed $result   Value returned by `$wpdb::insert()`.
	 * @param int   $insertId Value of `$wpdb->insert_id` afterwards.
	 */
	public static function insertSucceeded( mixed $result, int $insertId ): bool {
		return false !== $result && $insertId > 0;
	}

	/**
	 * Refuse to continue when an insert did not write.
	 *
	 * The alternative — reading `insert_id` and carrying on — is how a submission
	 * used to be lost silently: the entries row was rejected (the qa schema was one
	 * migration behind, so the table had no resume columns), the id came back 0, its
	 * field meta was written anyway at `entry_id` 0, and the visitor was shown the
	 * confirmation page while the entries list stayed empty. The submission handler
	 * turns this into an explicit "could not be stored" response, so a schema that
	 * cannot accept the row is loud instead of lossy.
	 *
	 * @param mixed  $result   Value returned by `$wpdb::insert()`.
	 * @param int    $insertId Value of `$wpdb->insert_id` afterwards.
	 * @param string $table    Table that was written to.
	 *
	 * @throws \RuntimeException When the insert did not write a row.
	 */
	private function assertInserted( mixed $result, int $insertId, string $table ): void {
		if ( self::insertSucceeded( $result, $insertId ) ) {
			return;
		}

		throw new \RuntimeException(
			'Could not write to ' . $table . ': ' . (string) $this->wpdb->last_error // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for the error log; it is never rendered to a visitor.
		);
	}
}
