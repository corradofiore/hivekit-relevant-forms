<?php
/**
 * Attention score unit tests (doc §19).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use RelevantForms\Ai\AttentionScore;

it(
	'computes the documented weighted formula as 0-100',
	function () {
		// legitimacy 1.0 * 0.35 + relevance 1.0 * 0.35 + urgency 1.0 * 0.30.
		expect( AttentionScore::calculate( 0.0, 1.0, 1.0 ) )->toBe( 100 );
		expect( AttentionScore::calculate( 1.0, 0.0, 0.0 ) )->toBe( 0 );
		expect( AttentionScore::calculate( 0.0, 0.0, 0.0 ) )->toBe( 35 );
	}
);

it(
	'clamps out-of-range inputs defensively',
	function () {
		expect( AttentionScore::calculate( -1.0, 2.0, 5.0 ) )->toBe( 100 );
		expect( AttentionScore::calculate( 2.0, -3.0, -1.0 ) )->toBe( 0 );
	}
);

it(
	'flags priority from 85 upwards',
	function () {
		expect( AttentionScore::isPriority( 84 ) )->toBeFalse();
		expect( AttentionScore::isPriority( 85 ) )->toBeTrue();
		expect( AttentionScore::isPriority( 100 ) )->toBeTrue();
	}
);
