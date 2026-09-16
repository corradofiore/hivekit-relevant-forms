<?php
/**
 * Form persistence.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Forms;

use RuntimeException;

/**
 * Repository for wp_sentinel_forms and wp_sentinel_form_versions.
 */
final class FormRepository {

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
	 * Create a form and return its ID.
	 *
	 * @param string     $name   Display name.
	 * @param FormSchema $schema Canonical schema.
	 * @param string     $status draft|published|archived.
	 */
	public function create( string $name, FormSchema $schema, string $status = 'draft' ): int {
		$table = $this->wpdb->prefix . 'sentinel_forms';
		$now   = current_time( 'mysql', true );

		$this->wpdb->insert(
			$table,
			[
				'name'        => $name,
				'status'      => $status,
				'schema_json' => wp_json_encode( $schema->toArray() ),
				'created_at'  => $now,
				'updated_at'  => $now,
			]
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update an existing form.
	 *
	 * @param int        $id     Form ID.
	 * @param string     $name   Display name.
	 * @param FormSchema $schema Canonical schema.
	 * @param string     $status draft|published|archived.
	 */
	public function update( int $id, string $name, FormSchema $schema, string $status ): bool {
		$table = $this->wpdb->prefix . 'sentinel_forms';

		$updated = $this->wpdb->update(
			$table,
			[
				'name'        => $name,
				'status'      => $status,
				'schema_json' => wp_json_encode( $schema->toArray() ),
				'updated_at'  => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $updated;
	}

	/**
	 * Find a form by ID (any status).
	 *
	 * @param int $id Form ID.
	 */
	public function find( int $id ): ?Form {
		$wpdb = $this->wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sentinel_forms WHERE id = %d', $id ) );

		return $row instanceof \stdClass ? Form::fromRow( $row ) : null;
	}

	/**
	 * Find a published form by ID.
	 *
	 * @param int $id Form ID.
	 */
	public function findPublished( int $id ): ?Form {
		$form = $this->find( $id );

		return $form !== null && 'published' === $form->status ? $form : null;
	}

	/**
	 * List forms, optionally filtered by status.
	 *
	 * @param array{status?: string, limit?: int, offset?: int} $args Query args.
	 * @return array<int, Form>
	 */
	public function all( array $args = [] ): array {
		$wpdb = $this->wpdb;

		$limit  = max( 1, (int) ( $args['limit'] ?? 100 ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		if ( ! empty( $args['status'] ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sentinel_forms WHERE status = %s ORDER BY updated_at DESC LIMIT %d OFFSET %d', (string) $args['status'], $limit, $offset )
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sentinel_forms ORDER BY updated_at DESC LIMIT %d OFFSET %d', $limit, $offset )
			);
		}

		return array_map( static fn ( object $row ): Form => Form::fromRow( $row ), (array) $rows );
	}

	/**
	 * Count forms, optionally filtered by status.
	 *
	 * @param array{status?: string} $args Query args.
	 */
	public function count( array $args = [] ): int {
		$wpdb = $this->wpdb;

		if ( ! empty( $args['status'] ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'sentinel_forms WHERE status = %s', (string) $args['status'] ) );
		}

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'sentinel_forms' );
	}

	/**
	 * Duplicate a form as a draft and return the new ID.
	 *
	 * @param int $id Source form ID.
	 * @throws RuntimeException When the source form does not exist.
	 */
	public function duplicate( int $id ): int {
		$form = $this->find( $id );

		if ( null === $form ) {
			throw new RuntimeException( 'Form not found.' );
		}

		$name = sprintf(
			/* translators: %s: original form name */
			__( '%s (copy)', 'sentinel-forms' ),
			$form->name
		);

		return $this->create( $name, $form->schema, 'draft' );
	}

	/**
	 * Archive a form (status archived).
	 *
	 * @param int $id Form ID.
	 */
	public function archive( int $id ): bool {
		return $this->setStatus( $id, 'archived' );
	}

	/**
	 * Delete a form. MVP: deletion equals archiving; permanent deletion will
	 * be governed by retention policies in a later phase (doc §7.1, §73).
	 *
	 * @param int $id Form ID.
	 */
	public function delete( int $id ): bool {
		return $this->setStatus( $id, 'archived' );
	}

	/**
	 * Set the form status.
	 *
	 * @param int    $id     Form ID.
	 * @param string $status New status.
	 */
	private function setStatus( int $id, string $status ): bool {
		$table = $this->wpdb->prefix . 'sentinel_forms';

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
	 * Snapshot the current schema as a new version and return the version row ID.
	 *
	 * @param int        $formId Form ID.
	 * @param FormSchema $schema Schema to snapshot.
	 */
	public function createVersion( int $formId, FormSchema $schema ): int {
		$wpdb = $this->wpdb;

		$table = $wpdb->prefix . 'sentinel_form_versions';

		$latest = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(version) FROM ' . $wpdb->prefix . 'sentinel_form_versions WHERE form_id = %d', $formId ) );

		$wpdb->insert(
			$table,
			[
				'form_id'     => $formId,
				'version'     => $latest + 1,
				'schema_json' => wp_json_encode( $schema->toArray() ),
				'created_at'  => current_time( 'mysql', true ),
			]
		);

		return (int) $wpdb->insert_id;
	}
}
