<?php
/**
 * Migration runner unit tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use RelevantForms\Database\MigrationRunner;
use RelevantForms\Tests\Unit\Support\WpdbDouble;

/**
 * The migrations read `$wpdb` from the global and interpolate its charset clause
 * into their DDL.
 */
beforeEach(
	function (): void {
		$GLOBALS['wpdb'] = new WpdbDouble(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the runner reads $wpdb from the global; this is its test double.
	}
);

/**
 * Read the private migration list so the ordering invariant can be asserted.
 *
 * @return array<int, class-string>
 */
function migration_runner_classes(): array {
	return ( new ReflectionClass( MigrationRunner::class ) )->getConstant( 'MIGRATIONS' );
}

/**
 * Every migration version, in the order the runner applies them.
 *
 * @return array<int, string>
 */
function migration_runner_versions(): array {
	return array_map(
		static function ( string $migrationClass ): string {
			$migration = new $migrationClass();

			return $migration->version();
		},
		migration_runner_classes()
	);
}

it(
	'targets the highest migration version',
	function (): void {
		$versions = migration_runner_versions();

		expect( MigrationRunner::targetVersion() )->toBe( max( $versions ) );

		/*
		 * MIGRATIONS is in dependency order, which is not version order: the logs
		 * table is 1.0.5 yet follows the 1.1.0 uploads table. Reading the target off
		 * the last entry would therefore be a latent bug, and this assertion is what
		 * keeps that visible if the list is ever reordered.
		 */
		$sorted = $versions;
		sort( $sorted, SORT_STRING );

		expect( $versions )->not->toBe( $sorted );
	}
);

it(
	'does nothing when the stored version is current',
	function (): void {
		Functions\when( 'get_option' )->justReturn( MigrationRunner::targetVersion() );
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'dbDelta' )->never();

		MigrationRunner::runIfBehind();
	}
);

it(
	'applies pending migrations when the stored version is behind',
	function (): void {
		// One release behind: the situation an in-place plugin update creates.
		Functions\when( 'get_option' )->justReturn( '1.1.0' );
		Functions\expect( 'dbDelta' )->atLeast()->once();
		Functions\expect( 'update_option' )
			->with( MigrationRunner::OPTION, MigrationRunner::targetVersion() )
			->atLeast()
			->once();

		MigrationRunner::runIfBehind();
	}
);

it(
	'runs every migration from an empty database',
	function (): void {
		Functions\when( 'get_option' )->justReturn( '0' );
		Functions\expect( 'dbDelta' )->times( count( migration_runner_classes() ) );
		Functions\expect( 'update_option' )->times( count( migration_runner_classes() ) );

		MigrationRunner::runIfBehind();
	}
);
