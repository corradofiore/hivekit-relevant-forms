<?php
/**
 * Minimal `$wpdb` stand-in for unit tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Unit\Support;

/**
 * Records what a collaborator tries to write, and can be told to fail the way a
 * rejected row does (`insert()` returns false, `last_error` is set, `insert_id`
 * keeps the value of the previous query).
 */
final class WpdbDouble {

	/**
	 * Table prefix, as `$wpdb` exposes it.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Last inserted id, kept between calls exactly like `$wpdb` does.
	 *
	 * @var int
	 */
	public int $insert_id = 0;

	/**
	 * Last error reported by the "database".
	 *
	 * @var string
	 */
	public string $last_error = '';

	/**
	 * When true, every insert is rejected.
	 *
	 * @var bool
	 */
	public bool $fail = false;

	/**
	 * Every insert attempt, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $inserts = [];

	/**
	 * Every delete, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $deletes = [];

	/**
	 * Every direct query, in order.
	 *
	 * @var list<string>
	 */
	public array $queries = [];

	/**
	 * Record an insert.
	 *
	 * @param string               $table  Table name.
	 * @param array<string, mixed> $data   Column values.
	 * @param mixed                $format Format specifiers, recorded as given.
	 */
	public function insert( string $table, array $data, mixed $format = null ): int|false {
		$this->inserts[] = [
			'table'  => $table,
			'data'   => $data,
			'format' => $format,
		];

		if ( $this->fail ) {
			/*
			 * Mirrors `$wpdb`: the failure is reported through `last_error` while
			 * `insert_id` keeps whatever the previous query left there — which is
			 * why a caller cannot judge success from the id alone.
			 */
			$this->last_error = "Unknown column 'resume_token_hash' in 'field list'";

			return false;
		}

		++$this->insert_id;

		return 1;
	}

	/**
	 * Record a delete.
	 *
	 * @param string               $table  Table name.
	 * @param array<string, mixed> $where  Where clause.
	 * @param mixed                $format Format specifiers, recorded as given.
	 */
	public function delete( string $table, array $where, mixed $format = null ): int {
		$this->deletes[] = [
			'table'  => $table,
			'data'   => $where,
			'format' => $format,
		];

		return 1;
	}

	/**
	 * Pass a query through.
	 *
	 * The arguments are deliberately ignored: every query in the plugin is fully
	 * prepared, and these tests assert on what is written, not on the SQL.
	 *
	 * @param string $query   Query.
	 * @param mixed  ...$args Prepared arguments.
	 */
	public function prepare( string $query, mixed ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- pass-through double mirrors the $wpdb signature.
		return $query;
	}

	/**
	 * Record a statement and report one affected row.
	 *
	 * @param string $query Query.
	 */
	public function query( string $query ): int {
		$this->queries[] = $query;

		return 1;
	}

	/**
	 * Charset clause the migrations interpolate into their SQL.
	 */
	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
}
