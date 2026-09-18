<?php
/**
 * Payment field types unit tests (payments phase 1).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Forms\Fields\FieldTypeRegistry;
use RelevantForms\Forms\Fields\PaymentField;
use RelevantForms\Payments\PaymentTotals;

beforeEach(
	function () {
		FieldTypeRegistry::all();

		Monkey\Functions\when( 'esc_attr' )->returnArg();
		Monkey\Functions\when( 'esc_html' )->returnArg();
		Monkey\Functions\when( 'selected' )->justReturn( '' );
		Monkey\Functions\when( 'checked' )->justReturn( '' );
	}
);

/**
 * Priced-choices fixture for the payment field tests.
 *
 * @param array<string, mixed> $overrides Definition overrides.
 * @return array<string, mixed>
 */
function rf_priced_definition( array $overrides = [] ): array {
	return array_merge(
		[
			'id'       => 'f_pay',
			'type'     => 'payment-select',
			'label'    => 'Merch',
			'currency' => 'usd',
			'choices'  => [ 'T-shirt|25.00', 'Cap|10', 'Broken' ],
		],
		$overrides
	);
}

it(
	'registers five payment types behind the shared base class',
	function () {
		foreach ( [ 'payment-single', 'payment-select', 'payment-multiple', 'payment-checkbox', 'payment-total' ] as $type ) {
			expect( FieldTypeRegistry::get( $type ) )->toBeInstanceOf( PaymentField::class );
		}
	}
);

it(
	'validates priced positions and rejects unknown ones',
	function () {
		$field      = FieldTypeRegistry::get( 'payment-select' );
		$definition = rf_priced_definition();

		expect( $field->validate( $definition, '' ) )->toBeNull();
		expect( $field->validate( $definition, '0' ) )->toBeNull();
		expect( $field->validate( $definition, '1' ) )->toBeNull();

		// Position 2 has no valid price; 99 does not exist.
		expect( $field->validate( $definition, '2' ) )->not->toBeNull();
		expect( $field->validate( $definition, '99' ) )->not->toBeNull();
		expect( $field->validate( $definition, 'abc' ) )->not->toBeNull();
	}
);

it(
	'sanitizes positions to their labels',
	function () {
		$definition = rf_priced_definition();

		expect( FieldTypeRegistry::get( 'payment-select' )->sanitize( $definition, '1' ) )->toBe( 'Cap' );
		expect( FieldTypeRegistry::get( 'payment-select' )->sanitize( $definition, '99' ) )->toBe( '' );

		$checked = FieldTypeRegistry::get( 'payment-checkbox' )->sanitize( $definition, [ '0', '1' ] );
		expect( $checked )->toBe( [ 'T-shirt', 'Cap' ] );
	}
);

it(
	'renders priced option labels and position values',
	function () {
		$html = FieldTypeRegistry::get( 'payment-select' )->render(
			rf_priced_definition(),
			'1',
			null,
			'7'
		);

		expect( $html )->toContain( '<option value="0"' );
		expect( $html )->toContain( 'T-shirt ($25.00)' );
		expect( $html )->toContain( 'Cap ($10.00)' );
		expect( $html )->toContain( 'Broken' );
	}
);

it(
	'computes contributions from definitions, not posted prices',
	function () {
		$definition = rf_priced_definition();

		expect( FieldTypeRegistry::get( 'payment-select' )->contributionMinor( $definition, '1' ) )->toBe( 1000 );
		expect( FieldTypeRegistry::get( 'payment-select' )->contributionMinor( $definition, '99' ) )->toBe( 0 );
		expect( FieldTypeRegistry::get( 'payment-checkbox' )->contributionMinor( $definition, [ '0', '1' ] ) )->toBe( 3500 );
	}
);

it(
	'handles fixed single items and donations',
	function () {
		$field = FieldTypeRegistry::get( 'payment-single' );

		$fixed = rf_priced_definition(
			[
				'type'   => 'payment-single',
				'amount' => '25.00',
			]
		);

		expect( $field->validate( $fixed, 'anything' ) )->toBeNull();
		expect( $field->contributionMinor( $fixed, null ) )->toBe( 2500 );
		expect( $field->contributionMinor( $fixed, '999.00' ) )->toBe( 2500 );
		expect( $field->sanitize( $fixed, 'x' ) )->toBeNull();

		$donation = rf_priced_definition(
			[
				'type'        => 'payment-single',
				'user_amount' => true,
				'min_amount'  => '5.00',
				'max_amount'  => '100.00',
			]
		);

		expect( $field->validate( $donation, '' ) )->toBeNull();
		expect( $field->validate( $donation, 'abc' ) )->not->toBeNull();
		expect( $field->validate( $donation, '1.00' ) )->not->toBeNull();
		expect( $field->validate( $donation, '12,50' ) )->toBeNull();
		expect( $field->validate( $donation, '200.00' ) )->not->toBeNull();

		expect( $field->sanitize( $donation, '12,50' ) )->toBe( '12.50' );
		expect( $field->contributionMinor( $donation, '12,50' ) )->toBe( 1250 );
	}
);

it(
	'renders the display-only types',
	function () {
		$single = FieldTypeRegistry::get( 'payment-single' )->render(
			rf_priced_definition(
				[
					'type'   => 'payment-single',
					'amount' => '25.00',
				]
			),
			null,
			null,
			'7'
		);

		expect( $single )->toContain( 'relevant-forms-payment-amount' );
		expect( $single )->toContain( '$25.00' );

		$total = FieldTypeRegistry::get( 'payment-total' )->render( rf_priced_definition( [ 'type' => 'payment-total' ] ), null, null, '7' );

		expect( $total )->toContain( 'data-rf-payment-total' );
		expect( $total )->toContain( '$0.00' );
		expect( FieldTypeRegistry::get( 'payment-total' )->sanitize( [], 'x' ) )->toBeNull();
	}
);

it(
	'aggregates server-authoritative totals across payment fields',
	function () {
		$definitions = [
			rf_priced_definition(
				[
					'id'     => 'f_fixed',
					'type'   => 'payment-single',
					'amount' => '25.00',
				]
			),
			rf_priced_definition( [ 'id' => 'f_select' ] ),
			rf_priced_definition(
				[
					'id'   => 'f_checks',
					'type' => 'payment-checkbox',
				]
			),
			[
				'id'   => 'f_name',
				'type' => 'text',
			],
			rf_priced_definition(
				[
					'id'   => 'f_total',
					'type' => 'payment-total',
				]
			),
		];

		$total = PaymentTotals::totalMinor(
			$definitions,
			[
				'f_select' => '1',
				'f_checks' => [ '0' ],
				'f_name'   => 'Ada',
			]
		);

		// 25.00 fixed + 10.00 select + 25.00 checkbox.
		expect( $total )->toBe( 6000 );
		expect( PaymentTotals::hasPaymentFields( $definitions ) )->toBeTrue();
		expect(
			PaymentTotals::hasPaymentFields(
				[
					[
						'id'   => 'f_name',
						'type' => 'text',
					],
				]
			)
		)->toBeFalse();
	}
);

it(
	'builds the payment breakdown with readable line items',
	function () {
		$definitions = [
			rf_priced_definition(
				[
					'id'     => 'f_fixed',
					'type'   => 'payment-single',
					'label'  => 'Deposit',
					'amount' => '25.00',
				]
			),
			rf_priced_definition(
				[
					'id'    => 'f_select',
					'label' => 'Merch',
				]
			),
			[
				'id'   => 'f_total',
				'type' => 'payment-total',
			],
		];

		$breakdown = PaymentTotals::breakdown(
			$definitions,
			[
				'f_select' => '1',
				'f_total'  => 'ignored',
			],
			'EUR'
		);

		expect( $breakdown['currency'] )->toBe( 'eur' );
		expect( $breakdown['total_minor'] )->toBe( 3500 );
		expect( $breakdown['items'] )->toHaveCount( 2 );
		expect( $breakdown['items'][0] )->toBe(
			[
				'field_id'     => 'f_fixed',
				'label'        => 'Deposit',
				'detail'       => '',
				'amount_minor' => 2500,
			]
		);
		expect( $breakdown['items'][1]['detail'] )->toBe( 'Cap' );
		expect( $breakdown['items'][1]['amount_minor'] )->toBe( 1000 );

		// Donations report the entered amount as their detail.
		$donation = PaymentTotals::breakdown(
			[
				rf_priced_definition(
					[
						'id'          => 'f_give',
						'type'        => 'payment-single',
						'label'       => 'Donation',
						'user_amount' => true,
					]
				),
			],
			[ 'f_give' => '12,50' ],
			'usd'
		);

		expect( $donation['total_minor'] )->toBe( 1250 );
		expect( $donation['items'][0]['detail'] )->toBe( '$12.50' );
	}
);

it(
	'carries live-total data attributes for the frontend script',
	function () {
		$select = FieldTypeRegistry::get( 'payment-select' )->render( rf_priced_definition(), '', null, '7' );

		expect( $select )->toContain( 'data-rf-price="2500"' );
		expect( $select )->toContain( 'data-rf-price="1000"' );

		$total = FieldTypeRegistry::get( 'payment-total' )->render( rf_priced_definition( [ 'type' => 'payment-total' ] ), null, null, '7' );

		expect( $total )->toContain( 'data-rf-symbol="$"' );
		expect( $total )->toContain( 'data-rf-decimals="2"' );

		$donation = FieldTypeRegistry::get( 'payment-single' )->render(
			rf_priced_definition(
				[
					'type'        => 'payment-single',
					'user_amount' => true,
				]
			),
			'10',
			null,
			'7'
		);

		expect( $donation )->toContain( 'data-rf-amount="1"' );
		expect( $donation )->toContain( 'data-rf-decimals="2"' );

		$fixed = FieldTypeRegistry::get( 'payment-single' )->render(
			rf_priced_definition(
				[
					'type'   => 'payment-single',
					'amount' => '25.00',
				]
			),
			null,
			null,
			'7'
		);

		expect( $fixed )->toContain( 'data-rf-price="2500"' );
	}
);
