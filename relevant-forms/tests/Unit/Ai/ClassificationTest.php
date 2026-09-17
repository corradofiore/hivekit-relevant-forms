<?php
/**
 * Classification validation unit tests (doc §16).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use RelevantForms\Ai\Classification;

it(
	'parses a valid payload and computes the attention score',
	function () {
		$classification = Classification::fromArray(
			[
				'spam_probability' => 0.03,
				'relevance'        => 0.98,
				'urgency'          => 0.87,
				'intent'           => 'quote_request',
				'category'         => 'new_customer',
				'summary'          => 'Urgent repair request.',
				'reason_code'      => 'relevant_enquiry',
			]
		);

		expect( $classification )->not->toBeNull();
		expect( $classification->reasonCode )->toBe( 'relevant_enquiry' );
		expect( $classification->attentionScore )->toBe( 94 );
		expect( $classification->toArray()['schema_version'] )->toBe( 1 );
	}
);

it(
	'rejects missing or out-of-range scores',
	function () {
		expect( Classification::fromArray( [ 'urgency' => 0.5 ] ) )->toBeNull();
		expect(
			Classification::fromArray(
				[
					'spam_probability' => 1.4,
					'relevance'        => 0.5,
					'urgency'          => 0.5,
				]
			)
		)->toBeNull();
	}
);

it(
	'falls back to the unknown reason code',
	function () {
		$classification = Classification::fromArray(
			[
				'spam_probability' => 0.9,
				'relevance'        => 0.1,
				'urgency'          => 0.0,
				'reason_code'      => 'made_up',
			]
		);

		expect( $classification->reasonCode )->toBe( 'unknown' );
	}
);
