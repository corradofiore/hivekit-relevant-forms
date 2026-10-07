<?php
/**
 * Resume token unit tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use RelevantForms\Entries\Resume\ResumeTokens;

it(
	'generates 64-character hex tokens',
	function () {
		$token = ResumeTokens::generate();

		expect( $token )->toMatch( '/^[0-9a-f]{64}$/' );
		expect( ResumeTokens::generate() )->not->toBe( $token );
	}
);

it(
	'hashes tokens with sha-256',
	function () {
		$hash = ResumeTokens::hash( 'abc' );

		expect( $hash )->toHaveLength( 64 );
		expect( $hash )->toBe( hash( 'sha256', 'abc' ) );
		expect( $hash )->not->toBe( 'abc' );
	}
);

it(
	'computes an expiry window in utc',
	function () {
		$before    = time();
		$timestamp = strtotime( ResumeTokens::expiry( 3 ) . ' UTC' );

		expect( $timestamp )->toBeGreaterThanOrEqual( $before + ( 3 * 86400 ) - 5 );
		expect( $timestamp )->toBeLessThanOrEqual( time() + ( 3 * 86400 ) + 5 );

		// Windows are clamped to the supported range.
		$min = strtotime( ResumeTokens::expiry( 0 ) . ' UTC' );
		$max = strtotime( ResumeTokens::expiry( 9999 ) . ' UTC' );

		expect( $min )->toBeGreaterThanOrEqual( $before + 86400 - 5 );
		expect( $max )->toBeLessThanOrEqual( time() + ( 365 * 86400 ) + 5 );
	}
);

it(
	'detects expired and missing expiries',
	function () {
		expect( ResumeTokens::expired( gmdate( 'Y-m-d H:i:s', time() - 60 ) ) )->toBeTrue();
		expect( ResumeTokens::expired( gmdate( 'Y-m-d H:i:s', time() + 60 ) ) )->toBeFalse();
		expect( ResumeTokens::expired( '' ) )->toBeTrue();
	}
);
