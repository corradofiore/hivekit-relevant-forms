<?php
/**
 * LegalLinks unit tests (v2 §13 external-service disclosure).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use Brain\Monkey;
use SentinelForms\Cloud\LegalLinks;

beforeEach(
	function () {
		Monkey\Functions\when( 'apply_filters' )->alias(
			static fn ( string $hook, mixed $value ): mixed => $value
		);
		Monkey\Functions\when( 'esc_url_raw' )->alias(
			static fn ( string $url ): string => $url
		);
	}
);

it(
	'returns no links until the service operator configures them',
	function () {
		expect( LegalLinks::all() )->toBe( [] );
		expect( LegalLinks::get( 'terms' ) )->toBe( '' );
	}
);

it(
	'reads wp-config constants and skips unconfigured keys',
	function () {
		define( 'SENTINEL_CLOUD_TERMS_URL', 'https://example.com/terms' );
		define( 'SENTINEL_CLOUD_PRIVACY_URL', 'https://example.com/privacy' );

		expect( LegalLinks::get( 'terms' ) )->toBe( 'https://example.com/terms' );
		expect( LegalLinks::get( 'subprocessors' ) )->toBe( '' );
		expect( LegalLinks::all() )->toBe(
			[
				'terms'   => 'https://example.com/terms',
				'privacy' => 'https://example.com/privacy',
			]
		);
	}
);
