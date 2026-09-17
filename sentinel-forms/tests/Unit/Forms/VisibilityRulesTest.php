<?php
/**
 * VisibilityRules unit tests.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use Brain\Monkey;
use SentinelForms\Forms\Visibility\VisibilityRules;

$rule = static function ( string $field, string $operator, string $value = '' ): array {
	return [
		'field'    => $field,
		'operator' => $operator,
		'value'    => $value,
	];
};

$config = static function ( string $state, array $groups ): array {
	return [
		'default' => $state,
		'groups'  => $groups,
	];
};

$field = static function ( string $id, string $type = 'text', string $label = '' ): array {
	return [
		'id'    => $id,
		'type'  => $type,
		'label' => '' !== $label ? $label : $id,
	];
};

$target = static function ( array $configuration ) use ( $field ): array {
	return array_merge( $field( 'f_extra' ), [ 'visibility' => $configuration ] );
};

it(
	'normalizes configurations and drops incomplete rules',
	function () use ( $rule, $config ) {
		$normalized = VisibilityRules::normalize(
			$config(
				'hidden',
				[
					[
						$rule( 'f_a', 'is', 'yes' ),
						$rule( '', 'is', 'x' ),
						$rule( 'f_b', 'no_such_operator', 'x' ),
					],
					[
						$rule( '', 'is', 'x' ),
					],
				]
			)
		);

		expect( $normalized )->toBe(
			[
				'default' => 'hidden',
				'groups'  => [
					[ $rule( 'f_a', 'is', 'yes' ) ],
				],
			]
		);

		// Unknown defaults fall back to "shown"; junk is ignored entirely.
		expect( VisibilityRules::normalize( $config( 'bogus', [ [] ] ) ) )->toBe( [] );
		expect( VisibilityRules::normalize( 'nope' ) )->toBe( [] );
	}
);

it(
	'accepts legacy operator spellings, including both spellings of ends with',
	function () {
		$legacy = [
			'==' => 'is',
			'!=' => 'is_not',
			'c'  => 'contains',
			'!c' => 'not_contains',
			'^'  => 'starts_with',
			'~'  => 'ends_with',
			'$'  => 'ends_with',
			'e'  => 'empty',
			'!e' => 'not_empty',
			'>'  => 'greater_than',
			'<'  => 'less_than',
		];

		foreach ( $legacy as $alias => $native ) {
			$normalized = VisibilityRules::normalize(
				[
					'groups' => [
						[
							[
								'field'    => 'f_a',
								'operator' => $alias,
								'value'    => 'x',
							],
						],
					],
				]
			);

			expect( $normalized['groups'][0][0]['operator'] )->toBe( $native );
		}
	}
);

it(
	'flips the default when a group matches',
	function () use ( $rule, $config, $field, $target ) {
		$fields = [
			$target( $config( 'hidden', [ [ $rule( 'f_plan', 'is', 'pro' ) ] ] ) ),
			$field( 'f_plan', 'select', 'Plan' ),
		];

		expect( VisibilityRules::evaluate( $fields, [ 'f_plan' => 'PRO' ] ) )
			->toBe( [ 'f_extra' => true ] );

		expect( VisibilityRules::evaluate( $fields, [ 'f_plan' => 'free' ] ) )
			->toBe( [ 'f_extra' => false ] );

		// Default shown: the same rule hides the field when it matches.
		$fields = [
			$target( $config( 'shown', [ [ $rule( 'f_plan', 'is', 'pro' ) ] ] ) ),
			$field( 'f_plan', 'select', 'Plan' ),
		];

		expect( VisibilityRules::evaluate( $fields, [ 'f_plan' => 'free' ] ) )
			->toBe( [ 'f_extra' => true ] );

		expect( VisibilityRules::evaluate( $fields, [ 'f_plan' => 'pro' ] ) )
			->toBe( [ 'f_extra' => false ] );
	}
);

it(
	'requires every rule in a group and any group overall',
	function () use ( $rule, $config, $field, $target ) {
		$fields = [
			$target(
				$config(
					'hidden',
					[
						[ $rule( 'f_a', 'is', 'yes' ), $rule( 'f_b', 'not_empty' ) ],
						[ $rule( 'f_c', 'is', 'maybe' ) ],
					]
				)
			),
			$field( 'f_a' ),
			$field( 'f_b' ),
			$field( 'f_c' ),
		];

		expect(
			VisibilityRules::evaluate(
				$fields,
				[
					'f_a' => 'yes',
					'f_b' => 'x',
					'f_c' => 'no',
				]
			)
		)
			->toBe( [ 'f_extra' => true ] );

		expect(
			VisibilityRules::evaluate(
				$fields,
				[
					'f_a' => 'yes',
					'f_b' => '',
					'f_c' => 'no',
				]
			)
		)
			->toBe( [ 'f_extra' => false ] );

		expect(
			VisibilityRules::evaluate(
				$fields,
				[
					'f_a' => 'no',
					'f_b' => 'x',
					'f_c' => 'maybe',
				]
			)
		)
			->toBe( [ 'f_extra' => true ] );

		expect(
			VisibilityRules::evaluate(
				$fields,
				[
					'f_a' => 'no',
					'f_b' => '',
					'f_c' => 'no',
				]
			)
		)
			->toBe( [ 'f_extra' => false ] );
	}
);

it(
	'compares text case-insensitively with contains, starts with and ends with',
	function () use ( $rule, $config, $field, $target ) {
		$cases = [
			[ 'contains', 'ell', true ],
			[ 'contains', 'ELL', true ],
			[ 'contains', 'zed', false ],
			[ 'contains', '', true ],
			[ 'not_contains', 'zed', true ],
			[ 'not_contains', 'ell', false ],
			[ 'starts_with', 'he', true ],
			[ 'starts_with', 'lo', false ],
			[ 'ends_with', 'lo', true ],
			[ 'ends_with', 'he', false ],
		];

		foreach ( $cases as $case ) {
			$fields = [
				$target( $config( 'hidden', [ [ $rule( 'f_a', $case[0], $case[1] ) ] ] ) ),
				$field( 'f_a' ),
			];

			expect( VisibilityRules::evaluate( $fields, [ 'f_a' => 'Hello' ] )['f_extra'] )->toBe( $case[2] );
		}
	}
);

it(
	'treats whitespace-only values as empty and checks multi-values element by element',
	function () use ( $rule, $config, $field, $target ) {
		$emptyFields = [
			$target( $config( 'hidden', [ [ $rule( 'f_a', 'empty' ) ] ] ) ),
			$field( 'f_a' ),
		];

		expect( VisibilityRules::evaluate( $emptyFields, [ 'f_a' => '' ] )['f_extra'] )->toBeTrue();
		expect( VisibilityRules::evaluate( $emptyFields, [ 'f_a' => '   ' ] )['f_extra'] )->toBeTrue();
		expect( VisibilityRules::evaluate( $emptyFields, [ 'f_a' => 'x' ] )['f_extra'] )->toBeFalse();
		expect( VisibilityRules::evaluate( $emptyFields, [ 'f_a' => [] ] )['f_extra'] )->toBeTrue();
		expect( VisibilityRules::evaluate( $emptyFields, [ 'f_a' => [ '', ' ' ] ] )['f_extra'] )->toBeTrue();
		expect( VisibilityRules::evaluate( $emptyFields, [ 'f_a' => [ 'x' ] ] )['f_extra'] )->toBeFalse();

		$notEmptyFields = [
			$target( $config( 'hidden', [ [ $rule( 'f_a', 'not_empty' ) ] ] ) ),
			$field( 'f_a' ),
		];

		expect( VisibilityRules::evaluate( $notEmptyFields, [ 'f_a' => 'x' ] )['f_extra'] )->toBeTrue();
		expect( VisibilityRules::evaluate( $notEmptyFields, [ 'f_a' => [ '', 'x' ] ] )['f_extra'] )->toBeTrue();
		expect( VisibilityRules::evaluate( $notEmptyFields, [ 'f_a' => '   ' ] )['f_extra'] )->toBeFalse();
	}
);

it(
	'matches multi-value fields by membership',
	function () use ( $rule, $config, $field, $target ) {
		$cases = [
			[ 'is', 'red', true ],
			[ 'is', 'green', false ],
			[ 'is_not', 'green', true ],
			[ 'is_not', 'red', false ],
			[ 'contains', 'lu', true ],
			[ 'greater_than', '50', true ],
		];

		foreach ( $cases as $case ) {
			$fields = [
				$target( $config( 'hidden', [ [ $rule( 'f_a', $case[0], $case[1] ) ] ] ) ),
				$field( 'f_a', 'checkbox' ),
			];

			expect( VisibilityRules::evaluate( $fields, [ 'f_a' => [ '3', '70', 'red', 'blue' ] ] )['f_extra'] )->toBe( $case[2] );
		}
	}
);

it(
	'compares numbers numerically and falls back to strings otherwise',
	function () use ( $rule, $config, $field, $target ) {
		$cases = [
			[ 'greater_than', '1000', '999', true ],
			[ 'greater_than', '1,000', '999', true ],
			[ 'greater_than', '12', '100', false ],
			[ 'less_than', '9', '10', true ],
			[ 'less_than', '9', '8', false ],
			[ 'greater_than', '5', '5', false ],
			[ 'less_than', '5.0', '5', false ],
			[ 'greater_than', '2026-09-17', '2026-01-01', true ],
			[ 'less_than', '2026-09-17', '2026-01-01', false ],
			[ 'greater_than', 'beta', 'alpha', true ],
		];

		foreach ( $cases as $case ) {
			$fields = [
				$target( $config( 'hidden', [ [ $rule( 'f_a', $case[0], $case[2] ) ] ] ) ),
				$field( 'f_a', 'number' ),
			];

			expect( VisibilityRules::evaluate( $fields, [ 'f_a' => $case[1] ] )['f_extra'] )->toBe( $case[3] );
		}
	}
);

it(
	'never matches rules whose source field is gone from the form',
	function () use ( $rule, $config, $target ) {
		$isFields = [
			$target( $config( 'hidden', [ [ $rule( 'f_gone', 'is', 'yes' ) ] ] ) ),
		];

		// The value is present in the request, but the source field is not
		// part of the form anymore: no match, no flip.
		expect( VisibilityRules::evaluate( $isFields, [ 'f_gone' => 'yes' ] ) )
			->toBe( [ 'f_extra' => false ] );

		$isNotFields = [
			$target( $config( 'hidden', [ [ $rule( 'f_gone', 'is_not', 'yes' ) ] ] ) ),
		];

		expect( VisibilityRules::evaluate( $isNotFields, [ 'f_gone' => 'yes' ] ) )
			->toBe( [ 'f_extra' => false ] );
	}
);

it(
	'only evaluates fields that carry visibility rules',
	function () use ( $rule, $config, $field, $target ) {
		$fields = [
			$target( $config( 'hidden', [ [ $rule( 'f_a', 'is', 'yes' ) ] ] ) ),
			$field( 'f_a' ),
			$field( 'f_plain' ),
		];

		expect( array_keys( VisibilityRules::evaluate( $fields, [] ) ) )->toBe( [ 'f_extra' ] );

		expect( VisibilityRules::hasVisibility( $field( 'f_a' ) ) )->toBeFalse();
		expect( VisibilityRules::hasVisibility( $fields[0] ) )->toBeTrue();
	}
);

it(
	'summarizes the flip condition with labels and choice values',
	function () use ( $rule, $config, $target ) {
		Monkey\Functions\when( '__' )->returnArg();

		$fields = [
			[
				'id'      => 'f_type',
				'type'    => 'select',
				'label'   => 'Business type',
				'choices' => [
					[
						'label' => 'Other',
						'value' => 'other',
					],
					[
						'label' => 'Retail',
						'value' => 'retail',
					],
				],
			],
			[
				'id'    => 'f_email',
				'type'  => 'email',
				'label' => 'Email',
			],
			[
				'id'    => 'f_rating',
				'type'  => 'number',
				'label' => 'Rating',
			],
		];

		$definition = $target(
			$config(
				'hidden',
				[
					[ $rule( 'f_type', 'is', 'other' ), $rule( 'f_email', 'not_empty' ) ],
					[ $rule( 'f_rating', 'greater_than', '4' ) ],
				]
			)
		);

		expect( VisibilityRules::summary( $definition, $fields ) )
			->toBe( 'Shown when: Business type is Other and Email is not empty or Rating is greater than 4' );

		// Default shown flips the sentence.
		$shown = $target( $config( 'shown', [ [ $rule( 'f_type', 'is', 'other' ) ] ] ) );

		expect( VisibilityRules::summary( $shown, $fields ) )
			->toBe( 'Hidden when: Business type is Other' );

		// No rules: no summary.
		expect( VisibilityRules::summary( [ 'id' => 'f_x' ], $fields ) )->toBe( '' );
	}
);

it(
	'offers presence-style operators for choice sources only',
	function () {
		$presence = [ 'is', 'is_not', 'empty', 'not_empty' ];

		expect( VisibilityRules::operatorsFor( 'select' ) )->toBe( $presence );
		expect( VisibilityRules::operatorsFor( 'radio' ) )->toBe( $presence );
		expect( VisibilityRules::operatorsFor( 'checkbox' ) )->toBe( $presence );
		expect( VisibilityRules::operatorsFor( 'text' ) )->toBe( VisibilityRules::OPERATORS );
		expect( VisibilityRules::operatorsFor( 'number' ) )->toContain( 'greater_than' );

		Monkey\Functions\when( '__' )->returnArg();

		expect( array_keys( VisibilityRules::operatorLabels() ) )->toBe( VisibilityRules::OPERATORS );
	}
);
