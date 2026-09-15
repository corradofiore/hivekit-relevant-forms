<?php
/**
 * SpamScorer unit tests.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use SentinelForms\AntiSpam\SpamScorer;
use SentinelForms\AntiSpam\SpamSignals;
use SentinelForms\Entries\Entry;

it(
	'scores each signal with its documented weight',
	function () {
		$scorer = new SpamScorer();

		expect( $scorer->score( new SpamSignals( submissionTimeMs: 500 ) ) )->toBe( 0.4 );
		expect( $scorer->score( new SpamSignals( linksDetected: 5 ) ) )->toBe( 0.4 );
		expect( $scorer->score( new SpamSignals( duplicateCount: 1 ) ) )->toBe( 0.5 );
		expect( $scorer->score( new SpamSignals( recentSubmissions: 5, rateLimit: 5 ) ) )->toBe( 0.6 );
	}
);

it(
	'caps the score at 1.0',
	function () {
		$scorer = new SpamScorer();

		$score = $scorer->score(
			new SpamSignals(
				submissionTimeMs: 100,
				linksDetected: 10,
				duplicateCount: 3,
				recentSubmissions: 12,
				rateLimit: 5,
			)
		);

		expect( $score )->toBe( 1.0 );
	}
);

it(
	'ignores timing when the timestamp is missing',
	function () {
		$scorer = new SpamScorer();

		expect( $scorer->score( new SpamSignals( submissionTimeMs: 0 ) ) )->toBe( 0.0 );
	}
);

it(
	'classifies scores into workflow statuses',
	function () {
		$scorer = new SpamScorer();

		expect( $scorer->classify( 0.0 ) )->toBe( Entry::STATUS_NEW );
		expect( $scorer->classify( 0.4 ) )->toBe( Entry::STATUS_NEW );
		expect( $scorer->classify( 0.5 ) )->toBe( Entry::STATUS_SUSPICIOUS );
		expect( $scorer->classify( 0.6 ) )->toBe( Entry::STATUS_SUSPICIOUS );
		expect( $scorer->classify( 0.95 ) )->toBe( Entry::STATUS_SPAM );
		expect( $scorer->classify( 1.0 ) )->toBe( Entry::STATUS_SPAM );
	}
);
