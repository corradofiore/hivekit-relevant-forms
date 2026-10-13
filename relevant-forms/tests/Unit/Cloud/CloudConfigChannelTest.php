<?php
/**
 * Service-origin resolution and build-channel unit tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use RelevantForms\Cloud\CloudConfig;

it(
	'pins the shipped default to the production origin',
	function () {
		// The QA build rewrites this into its staged copy. If the source tree
		// ever carries the QA origin, a released plugin would talk to QA.
		expect( CloudConfig::DEFAULT_URL )->toBe( 'https://app.relevantforms.com' );
	}
);

it(
	'resolves an origin in precedence order',
	function () {
		$fallback = 'https://app.relevantforms.com';

		expect(
			CloudConfig::resolveOrigin( 'https://constant.example', 'https://filter.example', 'https://legacy.example', 'https://channel.example', $fallback )
		)->toBe( 'https://constant.example' );

		expect(
			CloudConfig::resolveOrigin( '', 'https://filter.example', 'https://legacy.example', 'https://channel.example', $fallback )
		)->toBe( 'https://filter.example' );

		expect(
			CloudConfig::resolveOrigin( '', '', 'https://legacy.example', 'https://channel.example', $fallback )
		)->toBe( 'https://legacy.example' );

		// The channel origin outranks only the built-in default: a site that
		// overrides the endpoint keeps its override on a QA build too.
		expect(
			CloudConfig::resolveOrigin( '', '', '', 'https://app.relevantforms.dev', $fallback )
		)->toBe( 'https://app.relevantforms.dev' );

		expect(
			CloudConfig::resolveOrigin( '', '', '', '', $fallback )
		)->toBe( $fallback );
	}
);

it(
	'normalises whitespace and a trailing slash',
	function () {
		expect( CloudConfig::resolveOrigin( '  https://constant.example/  ', '', '', '', '' ) )
			->toBe( 'https://constant.example' );

		// Blank-ish input must not be mistaken for a configured override.
		expect( CloudConfig::resolveOrigin( '   ', '', '', '', 'https://app.relevantforms.com' ) )
			->toBe( 'https://app.relevantforms.com' );
	}
);

it(
	'reports a production channel when no build has pinned one',
	function () {
		expect( CloudConfig::channel() )->toBe( CloudConfig::CHANNEL_PRODUCTION );
		expect( CloudConfig::isPreReleaseChannel() )->toBeFalse();
	}
);
