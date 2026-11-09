<?php
/**
 * Logger pruning integration tests: the persisted log table is capped.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Support\Logger;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class LoggerPruneTest extends TestCase {

	/**
	 * Older entries beyond the cap are removed, the newest are kept.
	 */
	public function testPruningKeepsOnlyTheNewestEntriesWithinTheCap(): void {
		$filter = static fn (): int => 3;

		add_filter( 'relevant_forms_log_max_rows', $filter );

		try {
			foreach ( [ 'e1', 'e2', 'e3', 'e4', 'e5' ] as $message ) {
				Logger::error( $message );
			}
		} finally {
			remove_filter( 'relevant_forms_log_max_rows', $filter );
		}

		global $wpdb;

		$messages = $wpdb->get_col( 'SELECT message FROM ' . $wpdb->prefix . 'relevant_logs ORDER BY id ASC' );

		$this->assertSame( [ 'e3', 'e4', 'e5' ], array_map( 'strval', (array) $messages ) );
	}

	/**
	 * A cap of zero disables trimming.
	 */
	public function testPruningCanBeDisabled(): void {
		$filter = static fn (): int => 0;

		add_filter( 'relevant_forms_log_max_rows', $filter );

		try {
			Logger::error( 'a1' );
			Logger::error( 'a2' );
		} finally {
			remove_filter( 'relevant_forms_log_max_rows', $filter );
		}

		global $wpdb;

		$this->assertSame( 2, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'relevant_logs' ) );
	}
}
