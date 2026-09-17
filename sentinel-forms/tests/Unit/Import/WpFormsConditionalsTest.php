<?php
/**
 * WPForms conditional logic conversion unit tests (branching phase 4).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use SentinelForms\Import\WpFormsConditionals;

/**
 * Raw WPForms fields fixture keyed by id (a text source and a select source).
 *
 * @param array<string, array<string, mixed>> $overrides Field overrides.
 * @return array<string, array<string, mixed>>
 */
function sf_cond_fields( array $overrides = [] ): array {
	return array_replace(
		[
			'1' => [
				'id'    => '1',
				'type'  => 'text',
				'label' => 'Name',
			],
			'2' => [
				'id'      => '2',
				'type'    => 'select',
				'label'   => 'Plan',
				'choices' => [
					'1' => [
						'label' => 'Pro',
						'value' => 'pro',
					],
					'2' => [
						'label' => 'Free',
					],
				],
			],
		],
		$overrides
	);
}

/**
 * Imported definitions fixture for the fields above.
 *
 * @param array<string, array<string, mixed>> $overrides Definition overrides.
 * @return array<string, array<string, mixed>>
 */
function sf_cond_definitions( array $overrides = [] ): array {
	return array_replace(
		[
			'1' => [
				'id'   => '1',
				'type' => 'text',
			],
			'2' => [
				'id'      => '2',
				'type'    => 'select',
				'choices' => [
					[
						'label' => 'Pro',
						'value' => 'pro',
					],
					[
						'label' => 'Free',
						'value' => 'Free',
					],
				],
			],
		],
		$overrides
	);
}

it(
	'returns an empty result for fields without conditionals',
	function () {
		$result = WpFormsConditionals::convert(
			[
				'id'   => '9',
				'type' => 'text',
			],
			sf_cond_fields(),
			sf_cond_definitions()
		);

		expect( $result['visibility'] )->toBe( [] );
		expect( $result['notes'] )->toBe( [] );
	}
);

it(
	'inverts the show and hide types into the default state',
	function () {
		$rule = [
			'1' => [
				'1' => [
					'field'    => '1',
					'operator' => '==',
					'value'    => 'Ada',
				],
			],
		];

		$shown = WpFormsConditionals::convert(
			[
				'conditional_type' => 'show',
				'conditionals'     => $rule,
			],
			sf_cond_fields(),
			sf_cond_definitions()
		);

		expect( $shown['visibility']['default'] )->toBe( 'hidden' );
		expect( $shown['notes'] )->toBe( [] );

		$hidden = WpFormsConditionals::convert(
			[
				'conditional_type' => 'hide',
				'conditionals'     => $rule,
			],
			sf_cond_fields(),
			sf_cond_definitions()
		);

		expect( $hidden['visibility']['default'] )->toBe( 'shown' );
	}
);

it(
	'maps every value-source operator (including legacy spellings)',
	function () {
		$operators = [
			'==' => 'is',
			'!=' => 'is_not',
			'c'  => 'contains',
			'!c' => 'not_contains',
			'^'  => 'starts_with',
			'~'  => 'ends_with',
			'e'  => 'empty',
			'!e' => 'not_empty',
			'>'  => 'greater_than',
			'<'  => 'less_than',
		];

		foreach ( $operators as $raw => $native ) {
			$result = WpFormsConditionals::convert(
				[
					'conditional_type' => 'show',
					'conditionals'     => [
						'1' => [
							'1' => [
								'field'    => '1',
								'operator' => $raw,
								'value'    => '1,000',
							],
						],
					],
				],
				sf_cond_fields(),
				sf_cond_definitions()
			);

			$mappedRule = $result['visibility']['groups'][0][0];

			expect( $mappedRule['operator'] )->toBe( $native );
			expect( $mappedRule['value'] )->toBe( in_array( $native, [ 'empty', 'not_empty' ], true ) ? '' : '1,000' );
		}
	}
);

it(
	'resolves choice keys to imported choice values',
	function () {
		$value = static function ( string $rawValue ): string {
			$result = WpFormsConditionals::convert(
				[
					'conditional_type' => 'show',
					'conditionals'     => [
						'1' => [
							'1' => [
								'field'    => '2',
								'operator' => '==',
								'value'    => $rawValue,
							],
						],
					],
				],
				sf_cond_fields(),
				sf_cond_definitions()
			);

			return $result['visibility']['groups'][0][0]['value'];
		};

		// Key 1 carries an explicit value, key 2 falls back to its label.
		expect( $value( '1' ) )->toBe( 'pro' );
		expect( $value( '2' ) )->toBe( 'Free' );
	}
);

it(
	'maps the legacy choice operators onto equality',
	function () {
		$operators = [
			'==' => 'is',
			'c'  => 'is',
			'^'  => 'is',
			'~'  => 'is',
			'!=' => 'is_not',
			'!c' => 'is_not',
			'e'  => 'empty',
			'!e' => 'not_empty',
		];

		foreach ( $operators as $raw => $native ) {
			$result = WpFormsConditionals::convert(
				[
					'conditional_type' => 'show',
					'conditionals'     => [
						'1' => [
							'1' => [
								'field'    => '2',
								'operator' => $raw,
								'value'    => '1',
							],
						],
					],
				],
				sf_cond_fields(),
				sf_cond_definitions()
			);

			expect( $result['visibility']['groups'][0][0]['operator'] )->toBe( $native );
		}
	}
);

it(
	'drops a whole group when any of its rules cannot be mapped',
	function () {
		$result = WpFormsConditionals::convert(
			[
				'conditional_type' => 'show',
				'conditionals'     => [
					'1' => [
						'1' => [
							'field'    => '1',
							'operator' => '==',
							'value'    => 'Ada',
						],
						'2' => [
							'field'    => '99', // Not part of the imported set.
							'operator' => '==',
							'value'    => 'x',
						],
					],
				],
			],
			sf_cond_fields(),
			sf_cond_definitions()
		);

		expect( $result['visibility'] )->toBe( [] );
		expect( implode( ' ', $result['notes'] ) )->toContain( 'rule group could not be imported' );
		expect( implode( ' ', $result['notes'] ) )->toContain( 'always visible' );
	}
);

it(
	'keeps the groups that convert while dropping the rest',
	function () {
		$result = WpFormsConditionals::convert(
			[
				'conditional_type' => 'show',
				'conditionals'     => [
					'1' => [
						'1' => [
							'field'    => '1',
							'operator' => '???',
							'value'    => 'x',
						],
					],
					'2' => [
						'1' => [
							'field'    => '1',
							'operator' => '!e',
							'value'    => '',
						],
					],
				],
			],
			sf_cond_fields(),
			sf_cond_definitions()
		);

		expect( $result['visibility']['groups'] )->toBe(
			[
				[
					[
						'field'    => '1',
						'operator' => 'not_empty',
						'value'    => '',
					],
				],
			]
		);
		expect( implode( ' ', $result['notes'] ) )->toContain( '1 conditional rule group could not be imported' );
	}
);

it(
	'ignores empty rules like the WPForms cleanup does',
	function () {
		$result = WpFormsConditionals::convert(
			[
				'conditional_type' => 'show',
				'conditionals'     => [
					'1' => [
						'1' => [
							'field'    => '',
							'operator' => '==',
							'value'    => 'x',
						],
						'2' => [
							'field'    => '1',
							'operator' => '==',
							'value'    => 'Ada',
						],
					],
				],
			],
			sf_cond_fields(),
			sf_cond_definitions()
		);

		expect( $result['notes'] )->toBe( [] );
		expect( $result['visibility']['groups'][0] )->toHaveCount( 1 );
	}
);

it(
	'notes the always-hidden degenerate case of a hide-type field',
	function () {
		$payload = [
			'conditional_type' => 'hide',
			'conditionals'     => [
				'1' => [
					'1' => [
						'field'    => '',
						'operator' => '==',
						'value'    => '',
					],
				],
			],
		];

		$result = WpFormsConditionals::convert( $payload, sf_cond_fields(), sf_cond_definitions() );

		expect( $result['visibility'] )->toBe( [] );
		expect( implode( ' ', $result['notes'] ) )->toContain( 'always hidden' );

		// The show type has the same empty input but behaves as "visible".
		$payload['conditional_type'] = 'show';

		expect( WpFormsConditionals::convert( $payload, sf_cond_fields(), sf_cond_definitions() )['notes'] )->toBe( [] );
	}
);
