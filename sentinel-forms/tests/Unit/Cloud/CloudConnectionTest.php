<?php
/**
 * CloudConnection state machine unit tests (v2 §8).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use Brain\Monkey;
use SentinelForms\Cloud\CloudConnection;

/**
 * Option store captured across the test.
 *
 * @var array<string, mixed>
 */
$GLOBALS['sf_cloud_store'] = [];

beforeEach(
	function () {
		$GLOBALS['sf_cloud_store'] = [];

		Monkey\Functions\when( 'get_option' )->alias(
			static function ( string $key, mixed $fallback = [] ): mixed {
				return $GLOBALS['sf_cloud_store'][ $key ] ?? $fallback;
			}
		);
		Monkey\Functions\when( 'update_option' )->alias(
			static function ( string $key, mixed $value ): bool {
				$GLOBALS['sf_cloud_store'][ $key ] = $value;

				return true;
			}
		);
		Monkey\Functions\when( 'current_time' )->justReturn( '2026-09-15 12:00:00' );
		Monkey\Functions\when( 'do_action' )->justReturn( null );
	}
);

it(
	'starts disconnected and requires explicit connect',
	function () {
		$connection = new CloudConnection();

		expect( $connection->state() )->toBe( CloudConnection::STATE_DISCONNECTED );
		expect( $connection->siteId() )->toBeNull();
		expect( $connection->capabilityEnabled( CloudConnection::CAP_AI ) )->toBeFalse();
	}
);

it(
	'connects to account_connected and stores server capabilities',
	function () {
		$connection = new CloudConnection();

		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme', [ 'deep_scan' => true ] );

		expect( $connection->state() )->toBe( CloudConnection::STATE_ACCOUNT_CONNECTED );
		expect( $connection->siteId() )->toBe( 'site-uuid-1' );
		expect( $connection->serverCapabilities() )->toBe( [ 'deep_scan' => true ] );
	}
);

it(
	'requires account_connected before enabling capabilities',
	function () {
		$connection = new CloudConnection();

		expect( $connection->enableCapability( CloudConnection::CAP_AI ) )->toBeFalse();
		expect( $connection->enableCapability( 'nonsense' ) )->toBeFalse();

		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );

		expect( $connection->enableCapability( CloudConnection::CAP_AI ) )->toBeTrue();
		expect( $connection->capabilityEnabled( CloudConnection::CAP_AI ) )->toBeTrue();
		expect( $connection->state() )->toBe( CloudConnection::STATE_AI_ENABLED );
	}
);

it(
	'stacks capabilities once the account is connected',
	function () {
		$connection = new CloudConnection();

		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );

		expect( $connection->enableCapability( CloudConnection::CAP_AI ) )->toBeTrue();
		expect( $connection->enableCapability( CloudConnection::CAP_SMS ) )->toBeTrue();

		expect( $connection->capabilityEnabled( CloudConnection::CAP_AI ) )->toBeTrue();
		expect( $connection->capabilityEnabled( CloudConnection::CAP_SMS ) )->toBeTrue();
		expect( $connection->state() )->toBe( CloudConnection::STATE_SMS_ENABLED );
	}
);

it(
	'disabling a capability recomputes the state and keeps the site bound',
	function () {
		$connection = new CloudConnection();

		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );
		$connection->enableCapability( CloudConnection::CAP_AI );
		$connection->enableCapability( CloudConnection::CAP_SMS );

		expect( $connection->disableCapability( CloudConnection::CAP_SMS ) )->toBeTrue();
		expect( $connection->capabilityEnabled( CloudConnection::CAP_SMS ) )->toBeFalse();
		expect( $connection->capabilityEnabled( CloudConnection::CAP_AI ) )->toBeTrue();
		expect( $connection->state() )->toBe( CloudConnection::STATE_AI_ENABLED );

		expect( $connection->disableCapability( CloudConnection::CAP_AI ) )->toBeTrue();
		expect( $connection->state() )->toBe( CloudConnection::STATE_ACCOUNT_CONNECTED );
		expect( $connection->siteId() )->toBe( 'site-uuid-1' );
	}
);

it(
	'disconnect clears everything and restores local-only operation',
	function () {
		$connection = new CloudConnection();

		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );
		$connection->enableCapability( CloudConnection::CAP_SMS );
		$connection->disconnect();

		expect( $connection->state() )->toBe( CloudConnection::STATE_DISCONNECTED );
		expect( $connection->siteId() )->toBeNull();
		expect( $connection->capabilityEnabled( CloudConnection::CAP_SMS ) )->toBeFalse();
	}
);

it(
	'ignores usage and capability refreshes while disconnected',
	function () {
		$connection = new CloudConnection();

		$connection->updateUsage( [ 'scans' => 5 ] );
		$connection->updateServerCapabilities( [ 'deep_scan' => true ] );
		$connection->updateSubscription(
			[
				'plan'   => 'cloud',
				'status' => 'active',
			]
		);

		expect( $connection->usage() )->toBe( [] );
		expect( $connection->serverCapabilities() )->toBe( [] );
		expect( $connection->subscription() )->toBe( [] );
	}
);

it(
	'stores and refreshes the subscription summary',
	function () {
		$connection = new CloudConnection();

		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );

		expect( $connection->subscription() )->toBe( [] );

		$connection->updateSubscription(
			[
				'plan'     => 'cloud',
				'status'   => 'active',
				'entitled' => true,
				'ends_at'  => null,
			]
		);

		expect( $connection->subscription()['plan'] )->toBe( 'cloud' );
		expect( $connection->subscription()['entitled'] )->toBeTrue();
	}
);

it(
	'stores the dashboard deep link reported by the connect response',
	function () {
		$connection = new CloudConnection();

		expect( $connection->dashboardUrl() )->toBeNull();

		$connection->connect(
			'site-uuid-1',
			'https://a.example',
			'Acme',
			[],
			'https://cloud.example.com/app/sites/site-uuid-1/billing'
		);

		expect( $connection->dashboardUrl() )->toBe( 'https://cloud.example.com/app/sites/site-uuid-1/billing' );

		// Reconnecting without a link (legacy config key) clears a stale value.
		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );

		expect( $connection->dashboardUrl() )->toBeNull();
	}
);
