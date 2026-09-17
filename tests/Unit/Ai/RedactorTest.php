<?php
/**
 * Redactor unit tests (doc §18).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Ai\Redactor;

beforeEach(
	function () {
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );
	}
);

it(
	'redacts emails, phones, IPs and URL paths by default',
	function () {
		Monkey\Functions\when( 'get_option' )->justReturn( [] );

		$redactor = new Redactor();

		expect( $redactor->redact( 'Write to jane@example.com or call +1 (555) 123-4567.' ) )
			->toBe( 'Write to [EMAIL] or call [PHONE].' );
		expect( $redactor->redact( 'Server 192.168.1.10 says hi' ) )->toBe( 'Server [IP] says hi' );
		expect( $redactor->redact( 'See https://spam.example.com/contact for more' ) )
			->toBe( 'See [URL domain="spam.example.com"] for more' );
	}
);

it(
	'redacts every string value of a submission map',
	function () {
		Monkey\Functions\when( 'get_option' )->justReturn( [] );

		$redacted = ( new Redactor() )->redactValues(
			[
				'name'  => 'Jane',
				'email' => 'jane@example.com',
			]
		);

		expect( $redacted )->toBe(
			[
				'name'  => 'Jane',
				'email' => '[EMAIL]',
			]
		);
	}
);

it(
	'passes values through when privacy mode is disabled',
	function () {
		Monkey\Functions\when( 'get_option' )->justReturn( [ 'ai_privacy_mode' => 0 ] );

		expect( ( new Redactor() )->redact( 'jane@example.com' ) )->toBe( 'jane@example.com' );
	}
);

it(
	'lets sites extend the redaction patterns',
	function () {
		Monkey\Functions\when( 'get_option' )->justReturn( [] );
		Monkey\Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, array $patterns ): array {
				if ( 'relevant_forms_ai_redaction' === $hook ) {
					$patterns['/ACME-\d+/'] = '[ORDER]';
				}

				return $patterns;
			}
		);

		expect( ( new Redactor() )->redact( 'Order ACME-1234 shipped' ) )->toBe( 'Order [ORDER] shipped' );
	}
);
