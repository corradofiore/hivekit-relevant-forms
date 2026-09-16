<?php
/**
 * Phone number normalization unit tests.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use SentinelForms\Alerts\PhoneNumber;

it(
	'normalizes international numbers to E.164',
	function () {
		expect( PhoneNumber::normalize( '+39 333 123 4567' ) )->toBe( '+393331234567' );
		expect( PhoneNumber::normalize( '+1 (555) 000-1111' ) )->toBe( '+15550001111' );
		expect( PhoneNumber::normalize( '0049 151 1234567' ) )->toBe( '+491511234567' );
		expect( PhoneNumber::normalize( '  +393331234567  ' ) )->toBe( '+393331234567' );
		expect( PhoneNumber::normalize( '+39.333/123-4567' ) )->toBe( '+393331234567' );
	}
);

it(
	'rejects numbers without an explicit country code',
	function () {
		expect( PhoneNumber::normalize( '3331234567' ) )->toBeNull();
		expect( PhoneNumber::normalize( '(39) 333 123 4567' ) )->toBeNull();
		expect( PhoneNumber::normalize( 'not-a-phone' ) )->toBeNull();
		expect( PhoneNumber::normalize( '' ) )->toBeNull();
		expect( PhoneNumber::normalize( '   ' ) )->toBeNull();
	}
);

it(
	'rejects malformed international numbers',
	function () {
		expect( PhoneNumber::normalize( '+0123456789' ) )->toBeNull();
		expect( PhoneNumber::normalize( '+39' ) )->toBeNull();
		expect( PhoneNumber::normalize( '+1234567890123456' ) )->toBeNull();
	}
);
