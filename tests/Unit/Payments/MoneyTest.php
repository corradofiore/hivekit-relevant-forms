<?php
/**
 * Money helper unit tests (payments phase 1).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use RelevantForms\Payments\Money;

it(
	'normalizes currency codes with a supported fallback',
	function () {
		expect( Money::normalizeCurrency( 'EUR' ) )->toBe( 'eur' );
		expect( Money::normalizeCurrency( ' jpy ' ) )->toBe( 'jpy' );
		expect( Money::normalizeCurrency( 'xxc' ) )->toBe( Money::DEFAULT_CURRENCY );
		expect( Money::normalizeCurrency( null ) )->toBe( Money::DEFAULT_CURRENCY );
	}
);

it(
	'knows currency decimal places',
	function () {
		expect( Money::decimals( 'usd' ) )->toBe( 2 );
		expect( Money::decimals( 'jpy' ) )->toBe( 0 );
		expect( Money::decimals( 'kwd' ) )->toBe( 3 );
	}
);

it(
	'parses decimal amounts into minor units',
	function () {
		expect( Money::toMinor( '10', 'usd' ) )->toBe( 1000 );
		expect( Money::toMinor( '10.5', 'usd' ) )->toBe( 1050 );
		expect( Money::toMinor( '10.50', 'usd' ) )->toBe( 1050 );
		expect( Money::toMinor( '10,50', 'usd' ) )->toBe( 1050 );
		expect( Money::toMinor( '0.05', 'usd' ) )->toBe( 5 );
		expect( Money::toMinor( 25, 'usd' ) )->toBe( 2500 );
		expect( Money::toMinor( '500', 'jpy' ) )->toBe( 500 );
		expect( Money::toMinor( '1.234', 'kwd' ) )->toBe( 1234 );
	}
);

it(
	'rejects malformed amounts',
	function () {
		expect( Money::toMinor( 'abc', 'usd' ) )->toBeNull();
		expect( Money::toMinor( '10.5.0', 'usd' ) )->toBeNull();
		expect( Money::toMinor( '', 'usd' ) )->toBeNull();
		expect( Money::toMinor( '1e5', 'usd' ) )->toBeNull();
		expect( Money::toMinor( str_repeat( '9', 16 ), 'usd' ) )->toBeNull();
	}
);

it(
	'rounds extra decimals half-up with carry',
	function () {
		expect( Money::toMinor( '10.994', 'usd' ) )->toBe( 1099 );
		expect( Money::toMinor( '10.995', 'usd' ) )->toBe( 1100 );
		expect( Money::toMinor( '9.999', 'usd' ) )->toBe( 1000 );
		expect( Money::toMinor( '500.4', 'jpy' ) )->toBe( 500 );
		expect( Money::toMinor( '500.5', 'jpy' ) )->toBe( 501 );
	}
);

it(
	'renders minor units as plain decimals',
	function () {
		expect( Money::toDecimal( 1050, 'usd' ) )->toBe( '10.50' );
		expect( Money::toDecimal( 5, 'usd' ) )->toBe( '0.05' );
		expect( Money::toDecimal( 500, 'jpy' ) )->toBe( '500' );
		expect( Money::toDecimal( 1234, 'kwd' ) )->toBe( '1.234' );
	}
);

it(
	'formats amounts for display',
	function () {
		expect( Money::format( 2500, 'usd' ) )->toBe( '$25.00' );
		expect( Money::format( 2550, 'eur' ) )->toBe( '€25.50' );
		expect( Money::format( 2500, 'jpy' ) )->toBe( '¥2,500' );
		expect( Money::format( 1000, 'sek' ) )->toBe( '10.00 SEK' );
	}
);

it(
	'computes the platform fee in minor units',
	function () {
		expect( Money::feeMinor( 2500, 2.0 ) )->toBe( 50 );
		expect( Money::feeMinor( 2555, 2.0 ) )->toBe( 51 );
		expect( Money::feeMinor( 1, 2.0 ) )->toBe( 0 );
		expect( Money::feeMinor( 0, 2.0 ) )->toBe( 0 );
		expect( Money::feeMinor( 100, 0.0 ) )->toBe( 0 );
	}
);

it(
	'applies conservative minimum amounts per currency',
	function () {
		expect( Money::minAmountMinor( 'usd' ) )->toBe( 50 );
		expect( Money::minAmountMinor( 'jpy' ) )->toBe( 1 );
		expect( Money::minAmountMinor( 'kwd' ) )->toBe( 500 );
	}
);
