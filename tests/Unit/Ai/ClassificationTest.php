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
it(
	'keeps the two summaries apart and caps the short one',
	function () {
		$classification = Classification::fromArray(
			[
				'spam_probability' => 0.02,
				'relevance'        => 0.9,
				'urgency'          => 0.9,
				'summary'          => 'Burst pipe in the basement.',
				'sms_summary'      => 'Burst pipe basement.',
				'reason_code'      => 'relevant_enquiry',
			]
		);

		expect( $classification->summary )->toBe( 'Burst pipe in the basement.' );
		expect( $classification->smsSummary )->toBe( 'Burst pipe basement.' );
		expect( $classification->toArray()['sms_summary'] )->toBe( 'Burst pipe basement.' );

		$long = Classification::fromArray(
			[
				'spam_probability' => 0.02,
				'relevance'        => 0.9,
				'urgency'          => 0.9,
				'sms_summary'      => str_repeat( 'a', 200 ),
			]
		);

		expect( mb_strlen( $long->smsSummary ) )->toBe( 60 );
	}
);

/*
 * A Cloud older than the two-summary contract sends one phrase. The SMS alert
 * and the entries list column both read `smsSummary`, so the fallback has to
 * live in the parser: otherwise the text message would lose its context, or the
 * column would show a phrase the alert never sends.
 */
it(
	'reuses the email summary when the Cloud sends only one',
	function () {
		$classification = Classification::fromArray(
			[
				'spam_probability' => 0.02,
				'relevance'        => 0.9,
				'urgency'          => 0.9,
				'summary'          => 'Burst pipe in the basement.',
				'reason_code'      => 'relevant_enquiry',
			]
		);

		expect( $classification->smsSummary )->toBe( 'Burst pipe in the basement.' );
		expect( $classification->toArray()['sms_summary'] )->toBe( 'Burst pipe in the basement.' );

		$blank = Classification::fromArray(
			[
				'spam_probability' => 0.02,
				'relevance'        => 0.9,
				'urgency'          => 0.9,
				'summary'          => 'Quote request',
				'sms_summary'      => '   ',
			]
		);

		expect( $blank->smsSummary )->toBe( 'Quote request' );
	}
);
