<?php
/**
 * Ordered database migration runner.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Database;

use RelevantForms\Database\Migrations\AddResumeColumnsToEntries;
use RelevantForms\Database\Migrations\CreateEntriesTable;
use RelevantForms\Database\Migrations\CreateEntryMetaTable;
use RelevantForms\Database\Migrations\CreateEventsTable;
use RelevantForms\Database\Migrations\CreateFormVersionsTable;
use RelevantForms\Database\Migrations\CreateFormsTable;
use RelevantForms\Database\Migrations\CreateLogsTable;
use RelevantForms\Database\Migrations\CreateUploadsTable;

/**
 * Applies migrations newer than the version stored in the database option.
 */
final class MigrationRunner {

	/**
	 * Option holding the applied schema version.
	 */
	public const OPTION = 'relevant_forms_db_version';

	/**
	 * Migrations, in dependency order.
	 *
	 * @var array<int, class-string<Migration>>
	 */
	private const MIGRATIONS = [
		CreateFormsTable::class,
		CreateFormVersionsTable::class,
		CreateEntriesTable::class,
		CreateEntryMetaTable::class,
		CreateEventsTable::class,
		CreateUploadsTable::class,
		CreateLogsTable::class,
		AddResumeColumnsToEntries::class,
	];

	/**
	 * Run every pending migration.
	 */
	public static function run(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$current = (string) get_option( self::OPTION, '0' );

		foreach ( self::MIGRATIONS as $migrationClass ) {
			$migration = new $migrationClass();

			if ( version_compare( $migration->version(), $current, '>' ) ) {
				$migration->up();
				update_option( self::OPTION, $migration->version() );
			}
		}
	}
}
