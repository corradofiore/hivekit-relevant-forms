<?php
/**
 * Entry persistence guard unit tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Tests\Unit\Support\WpdbDouble;

/**
 * Install a fresh double as the repository's database handle.
 *
 * @param bool $fail     Whether inserts are rejected.
 * @param int  $insertId Id the double reports before any write.
 */
function entry_repository_wpdb( bool $fail = false, int $insertId = 0 ): WpdbDouble {
	$wpdb            = new WpdbDouble();
	$wpdb->fail      = $fail;
	$wpdb->insert_id = $insertId;

	$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the repository reads $wpdb from the global; this is its test double.

	return $wpdb;
}

/**
 * Build the entry the repository is asked to store.
 */
function entry_repository_fixture(): Entry {
	return new Entry(
		null,
		1,
		2,
		'new',
		'https://example.test/contact',
		'',
		'ua-hash',
		'ip-hash',
		'2026-10-11 12:00:00',
		'2026-10-11 12:00:00',
		[
			'f_name' => 'Ada',
			'f_msg'  => 'Please send a quote.',
		]
	);
}

/**
 * The timestamps the repository writes come from WordPress.
 */
beforeEach(
	function (): void {
		Functions\when( 'current_time' )->justReturn( '2026-10-11 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}
);

it(
	'treats a false result as a failed write even when an id is left behind',
	function (): void {
		expect( EntryRepository::insertSucceeded( false, 7 ) )->toBeFalse();
		expect( EntryRepository::insertSucceeded( 0, 0 ) )->toBeFalse();
		expect( EntryRepository::insertSucceeded( false, 0 ) )->toBeFalse();
		expect( EntryRepository::insertSucceeded( 1, 7 ) )->toBeTrue();
	}
);

it(
	'stores the entry and its field meta',
	function (): void {
		$wpdb = entry_repository_wpdb();

		$id = ( new EntryRepository() )->create( entry_repository_fixture() );

		expect( $id )->toBe( 1 );
		expect( $wpdb->inserts[0]['table'] )->toBe( 'wp_relevant_entries' );
		expect( $wpdb->inserts )->toHaveCount( 3 );   // The entry plus two fields.
		expect( $wpdb->deletes )->toHaveCount( 1 );   // Meta is cleared first.
	}
);

it(
	'refuses to continue when the entry row was rejected',
	function (): void {
		// A previous query left an id behind, which is how the old code was fooled.
		$wpdb = entry_repository_wpdb( true, 7 );

		$repository = new EntryRepository();

		expect( fn () => $repository->create( entry_repository_fixture() ) )
			->toThrow( RuntimeException::class );
		expect( $wpdb->last_error )->not->toBe( '' );

		/*
		 * The dangerous half of the bug: the field meta used to be written anyway,
		 * orphaned at entry_id 0, so the submission looked stored to the visitor and
		 * to the handler while the entries list stayed empty.
		 */
		expect( $wpdb->deletes )->toBe( [] );
		expect( $wpdb->inserts )->toHaveCount( 1 );
	}
);
