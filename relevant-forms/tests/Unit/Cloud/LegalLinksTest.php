<?php
/**
 * LegalLinks unit tests (v2 §13 external-service disclosure).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Cloud\LegalLinks;

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
		$links = LegalLinks::all();

		expect( $links )->toBe( [] );
		expect( $links['terms'] ?? '' )->toBe( '' );
	}
);

it(
	'reads wp-config constants and skips unconfigured keys',
	function () {
		define( 'RELEVANT_CLOUD_TERMS_URL', 'https://example.com/terms' );
		define( 'RELEVANT_CLOUD_PRIVACY_URL', 'https://example.com/privacy' );

		$links = LegalLinks::all();

		expect( $links['terms'] ?? '' )->toBe( 'https://example.com/terms' );
		expect( $links['subprocessors'] ?? '' )->toBe( '' );
		expect( $links )->toBe(
			[
				'terms'   => 'https://example.com/terms',
				'privacy' => 'https://example.com/privacy',
			]
		);
	}
);
