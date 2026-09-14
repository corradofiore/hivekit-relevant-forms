<?php
/**
 * Ordered database migration runner.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Database;

use SentinelForms\Database\Migrations\CreateEntriesTable;
use SentinelForms\Database\Migrations\CreateEntryMetaTable;
use SentinelForms\Database\Migrations\CreateEventsTable;
use SentinelForms\Database\Migrations\CreateFormVersionsTable;
use SentinelForms\Database\Migrations\CreateFormsTable;
use SentinelForms\Database\Migrations\CreateLogsTable;

/**
 * Applies migrations newer than the version stored in the database option.
 */
final class MigrationRunner {

	/**
	 * Option holding the applied schema version.
	 */
	public const OPTION = 'sentinel_forms_db_version';

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
		CreateLogsTable::class,
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
