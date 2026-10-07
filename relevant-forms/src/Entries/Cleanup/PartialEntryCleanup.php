<?php
/**
 * Expired partial-entry cleanup (Save and Resume).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Entries\Cleanup;

use RelevantForms\Entries\EntryRepository;
use RelevantForms\Support\Logger;

/**
 * Removes partial entries whose resume link has expired: the visitor can no
 * longer resume them, so the stored answers are deleted (docs/privacy.md).
 */
final class PartialEntryCleanup {

	/**
	 * Register the cron callback.
	 */
	public static function register(): void {
		add_action( 'relevant_forms_cleanup_partials', [ self::class, 'run' ] );
	}

	/**
	 * Run one cleanup pass.
	 */
	public static function run(): void {
		$deleted = ( new EntryRepository() )->pruneExpiredPartials( current_time( 'mysql', true ) );

		if ( $deleted > 0 ) {
			Logger::info( 'Expired partial entries pruned', [ 'deleted' => $deleted ] );
		}
	}
}
