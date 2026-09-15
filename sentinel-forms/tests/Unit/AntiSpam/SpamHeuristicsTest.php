<?php
/**
 * SpamHeuristics unit tests (pure functions only).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use Brain\Monkey;
use SentinelForms\AntiSpam\SpamHeuristics;

beforeEach(
	function () {
		Monkey\Functions\when( 'wp_json_encode' )->alias(
			static fn ( mixed $data ): string => (string) json_encode( $data ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure unit test stub, no WordPress bootstrap.
		);
	}
);

it(
	'counts URLs in string values only',
	function () {
		$heuristics = new SpamHeuristics();

		expect( $heuristics->linksIn( [ 'a' => 'see https://a.example and http://b.example' ] ) )->toBe( 2 );
		expect(
			$heuristics->linksIn(
				[
					'a' => 'no links here',
					'b' => [ 'https://ignored.example' ],
				]
			)
		)->toBe( 0 );
	}
);

it(
	'builds order-insensitive deterministic hashes',
	function () {
		$heuristics = new SpamHeuristics();

		$first  = $heuristics->submissionHash(
			[
				'b' => 'two',
				'a' => ' one ',
			]
		);
		$second = $heuristics->submissionHash(
			[
				'a' => 'one',
				'b' => 'two',
			]
		);

		expect( $first )->toBe( $second );
		expect( strlen( $first ) )->toBe( 64 );
	}
);

it(
	'measures elapsed time from the render timestamp',
	function () {
		$heuristics = new SpamHeuristics();
		$startedAt  = microtime( true ) - 2.0;

		$elapsed = $heuristics->elapsedMs( $startedAt );

		expect( $elapsed )->toBeGreaterThanOrEqual( 1500 );
		expect( $elapsed )->toBeLessThanOrEqual( 5000 );
	}
);
