<?php
/**
 * Activation integration tests: output, schema and scheduled events.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Ai\ClassificationRetry;
use RelevantForms\Bootstrap\Plugin;
use RelevantForms\Database\MigrationRunner;

/**
 * A clean activation prints nothing, creates the tables and schedules every
 * recurring event — including the ones using the custom recurrence.
 *
 * @group integration
 */
final class ActivationTest extends TestCase {

	/**
	 * Activation prints nothing: WordPress warns about unexpected output.
	 */
	public function testActivationPrintsNothing(): void {
		$this->clearPluginCrons();

		ob_start();
		Plugin::activate();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Every recurring event is scheduled, with the recurrence it declares.
	 */
	public function testActivationSchedulesEveryRecurringEvent(): void {
		$this->clearPluginCrons();

		Plugin::activate();

		$this->assertNotFalse( wp_next_scheduled( 'relevant_forms_cleanup_uploads' ) );
		$this->assertNotFalse( wp_next_scheduled( 'relevant_forms_cleanup_partials' ) );
		$this->assertNotFalse( wp_next_scheduled( 'relevant_forms_poll_scan_results' ) );
		$this->assertNotFalse( wp_next_scheduled( ClassificationRetry::HOOK ) );

		$this->assertSame( 'hourly', $this->scheduleOf( 'relevant_forms_cleanup_uploads' ) );
		$this->assertSame( 'daily', $this->scheduleOf( 'relevant_forms_cleanup_partials' ) );
		$this->assertSame( 'relevant_fifteen_minutes', $this->scheduleOf( 'relevant_forms_poll_scan_results' ) );
		$this->assertSame( 'relevant_fifteen_minutes', $this->scheduleOf( ClassificationRetry::HOOK ) );
	}

	/**
	 * A second activation does not duplicate the events.
	 */
	public function testActivationIsIdempotent(): void {
		$this->clearPluginCrons();

		Plugin::activate();
		Plugin::activate();

		$this->assertSame( 1, $this->countEvents( 'relevant_forms_poll_scan_results' ) );
		$this->assertSame( 1, $this->countEvents( ClassificationRetry::HOOK ) );
	}

	/**
	 * Activation records the current schema version and creates every table.
	 */
	public function testActivationCreatesThePluginTables(): void {
		global $wpdb;

		Plugin::activate();

		$this->assertSame( MigrationRunner::targetVersion(), (string) get_option( MigrationRunner::OPTION ) );

		foreach (
			[
				'relevant_forms',
				'relevant_form_versions',
				'relevant_entries',
				'relevant_entry_meta',
				'relevant_events',
				'relevant_uploads',
				'relevant_logs',
				'relevant_cloud_queue',
			] as $suffix
		) {
			$table = $wpdb->prefix . $suffix;

			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ),
				$table
			);
		}
	}

	/**
	 * Recurrence recorded for a hook's next run ('' when not scheduled).
	 *
	 * @param string $hook Cron hook.
	 */
	private function scheduleOf( string $hook ): string {
		$cron = _get_cron_array();

		foreach ( (array) $cron as $hooks ) {
			if ( ! isset( $hooks[ $hook ] ) ) {
				continue;
			}

			foreach ( (array) $hooks[ $hook ] as $event ) {
				return (string) ( $event['schedule'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * Number of scheduled events for a hook.
	 *
	 * @param string $hook Cron hook.
	 */
	private function countEvents( string $hook ): int {
		$count = 0;

		foreach ( (array) _get_cron_array() as $hooks ) {
			$count += isset( $hooks[ $hook ] ) ? count( (array) $hooks[ $hook ] ) : 0;
		}

		return $count;
	}

	/**
	 * Remove the plugin's events so activation starts from a clean slate.
	 */
	private function clearPluginCrons(): void {
		foreach (
			[
				'relevant_forms_cleanup_uploads',
				'relevant_forms_cleanup_partials',
				'relevant_forms_poll_scan_results',
				ClassificationRetry::HOOK,
			] as $hook
		) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
