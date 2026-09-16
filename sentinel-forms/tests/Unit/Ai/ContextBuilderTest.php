<?php
/**
 * ContextBuilder unit tests (doc §14).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use Brain\Monkey;
use SentinelForms\Ai\ContextBuilder;
use SentinelForms\Forms\Form;
use SentinelForms\Forms\FormSchema;

beforeEach(
	function () {
		Monkey\Functions\when( 'get_option' )->alias(
			static function ( string $key, mixed $fallback = [] ): mixed {
				if ( 'sentinel_forms_settings' === $key ) {
					return [
						'ai_site_purpose' => 'Plumbing company in Leeds',
						'ai_priorities'   => "emergency repairs\nquote requests\n\n",
						'ai_unwanted'     => 'SEO services',
					];
				}

				if ( 'sentinel_forms_preference_profile' === $key ) {
					return [
						'corrections' => [
							[
								'verdict'     => 'spam',
								'reason_code' => 'seo_solicitation',
								'summary'     => 'SEO pitch from jane@example.com',
								'at'          => '2026-09-01 10:00:00',
							],
						],
						'tallies'     => [
							'seo_solicitation' => [ 'spam' => 2 ],
						],
					];
				}

				return $fallback;
			}
		);
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );
		Monkey\Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
	}
);

it(
	'builds the full classification context with redacted values',
	function () {
		$schema = FormSchema::fromArray(
			[
				'name'      => 'Emergency',
				'ai_policy' => [
					'purpose' => 'Urgent repair requests',
					'spam'    => 'Automated sales pitches',
				],
				'fields'    => [
					[
						'id'    => 'message',
						'type'  => 'textarea',
						'label' => 'Message',
					],
				],
			]
		);
		$form   = new Form( 3, 'Emergency', 'published', $schema, '2026-01-01 00:00:00', '2026-01-01 00:00:00' );

		$protection = [
			'signals' => [
				'spam_score'         => 0.2,
				'links_detected'     => 1,
				'duplicate_count'    => 0,
				'submission_time_ms' => 4200,
				'recent_submissions' => 2,
				'rate_limit'         => 5,
			],
			'hash'    => 'hash-1',
		];

		$context = ( new ContextBuilder() )->build(
			$form,
			[ 'message' => 'Call me on 07700 900123 or jane@example.com' ],
			$protection
		);

		expect( $context['site_purpose'] )->toBe( 'Plumbing company in Leeds' );
		expect( $context['form_purpose'] )->toBe( 'Urgent repair requests' );
		expect( $context['form_spam'] )->toBe( 'Automated sales pitches' );
		expect( $context['priorities'] )->toBe( [ 'emergency repairs', 'quote requests' ] );
		expect( $context['unwanted'] )->toBe( [ 'SEO services' ] );
		expect( $context['field_labels'] )->toBe( [ 'message' => 'Message' ] );
		expect( $context['submission']['message'] )->toContain( '[EMAIL]' );
		expect( $context['submission']['message'] )->toContain( '[PHONE]' );
		expect( $context['local_signals']['submission_hash'] )->toBe( 'hash-1' );

		// Preference profile guidance from past corrections (doc §26–27).
		expect( $context['preference_profile']['usually_spam'] )->toBe( [ 'seo_solicitation' ] );
		expect( $context['recent_corrections'] )->toHaveCount( 1 );
		expect( $context['recent_corrections'][0]['verdict'] )->toBe( 'spam' );

		// Correction summaries are redacted before transmission too.
		expect( $context['recent_corrections'][0]['summary'] )->toContain( '[EMAIL]' );
	}
);
