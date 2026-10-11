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

	/**
	 * Apply pending migrations when the code is ahead of the stored version.
	 *
	 * WordPress fires the activation hook once, so a site that *updates* the
	 * plugin in place — which is how every customer gets a new release — never
	 * ran the migrator again. A stale schema here is not a degraded feature, it is
	 * lost data: the entries table was missing the resume columns for a release,
	 * and every submission was written into a table that could not accept it
	 * (see `EntryRepository::assertInserted()`). One autoloaded option read per
	 * request, and the work only happens when the stored version is behind.
	 */
	public static function runIfBehind(): void {
		if ( version_compare( self::targetVersion(), (string) get_option( self::OPTION, '0' ), '>' ) ) {
			self::run();
		}
	}

	/**
	 * Highest version among the migrations.
	 *
	 * MIGRATIONS is listed in dependency order, which is not version order — the
	 * logs table is 1.0.5 yet comes after the 1.1.0 uploads table — so the target is
	 * the maximum, not the last entry. (A unit test pins that they differ, which is
	 * what makes reading the last entry a latent bug rather than a shortcut.)
	 */
	public static function targetVersion(): string {
		$target = '0';

		foreach ( self::MIGRATIONS as $migrationClass ) {
			$migration = new $migrationClass();
			$version   = $migration->version();

			if ( version_compare( $version, $target, '>' ) ) {
				$target = $version;
			}
		}

		return $target;
	}
}
