<?php
/**
 * PreferenceProfile unit tests (doc §26–27).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Ai\PreferenceProfile;

/**
 * Option store used by the profile.
 *
 * @var array<string, mixed>
 */
$GLOBALS['rf_profile_store'] = [];

beforeEach(
	function () {
		$GLOBALS['rf_profile_store'] = [];

		Monkey\Functions\when( 'get_option' )->alias(
			static function ( string $key, mixed $fallback = [] ): mixed {
				return $GLOBALS['rf_profile_store'][ $key ] ?? $fallback;
			}
		);
		Monkey\Functions\when( 'update_option' )->alias(
			static function ( string $key, mixed $value ): bool {
				$GLOBALS['rf_profile_store'][ $key ] = $value;

				return true;
			}
		);
		Monkey\Functions\when( 'current_time' )->justReturn( '2026-09-15 12:00:00' );
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );
	}
);

/**
 * Sample classification payload.
 *
 * @param string $reason Reason code.
 */
function rf_profile_classification( string $reason ): array {
	return [
		'reason_code'     => $reason,
		'attention_score' => 42,
		'summary'         => 'Short summary.',
	];
}

it(
	'derives guidance after repeated corrections',
	function () {
		$profile = new PreferenceProfile();

		$profile->record( 'spam', rf_profile_classification( 'seo_solicitation' ) );
		$profile->record( 'spam', rf_profile_classification( 'seo_solicitation' ) );
		$profile->record( 'legitimate', rf_profile_classification( 'supplier_contact' ) );
		$profile->record( 'legitimate', rf_profile_classification( 'supplier_contact' ) );

		$guidance = $profile->guidance();

		expect( $guidance['usually_spam'] )->toBe( [ 'seo_solicitation' ] );
		expect( $guidance['usually_legitimate'] )->toBe( [ 'supplier_contact' ] );
	}
);

it(
	'requires at least two corrections before deriving guidance',
	function () {
		$profile = new PreferenceProfile();

		$profile->record( 'spam', rf_profile_classification( 'automated_spam' ) );

		$guidance = $profile->guidance();

		expect( $guidance['usually_spam'] )->toBe( [] );
		expect( $guidance['min_corrections'] )->toBe( 2 );
	}
);

it(
	'keeps a conflicting verdict out of the guidance',
	function () {
		$profile = new PreferenceProfile();

		$profile->record( 'spam', rf_profile_classification( 'job_application' ) );
		$profile->record( 'legitimate', rf_profile_classification( 'job_application' ) );

		expect( $profile->guidance()['usually_spam'] )->not->toContain( 'job_application' );
		expect( $profile->guidance()['usually_legitimate'] )->not->toContain( 'job_application' );
	}
);

it(
	'returns the most recent corrections first and caps the log',
	function () {
		$profile = new PreferenceProfile();

		// 51 corrections: the store keeps the most recent 50.
		for ( $iteration = 0; $iteration < 51; $iteration++ ) {
			$profile->record( 'spam', rf_profile_classification( 'automated_spam' ) );
		}

		$stored = $GLOBALS['rf_profile_store'][ PreferenceProfile::OPTION ];

		expect( count( $stored['corrections'] ) )->toBe( 50 );
		expect( $profile->recent( 1 ) )->toHaveCount( 1 );
		expect( $profile->recent( 1 )[0]['verdict'] )->toBe( 'spam' );
	}
);

it(
	'ignores unknown verdicts',
	function () {
		$profile = new PreferenceProfile();

		$profile->record( 'nonsense', rf_profile_classification( 'automated_spam' ) );

		expect( $GLOBALS['rf_profile_store'] )->toBe( [] );
	}
);
