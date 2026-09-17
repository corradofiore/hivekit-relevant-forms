<?php
/**
 * CloudClient billing endpoint unit tests (Phase 8).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Cloud\CloudClient;

beforeEach(
	function () {
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );
	}
);

/**
 * Capture the next HTTP request and answer with an envelope.
 *
 * @param array<string, mixed> $data Response data.
 */
function rf_capture_billing_request( array &$captured, array $data ): void {
	Monkey\Functions\when( 'wp_remote_post' )->alias(
		static function ( string $url, array $args ) use ( &$captured ): array {
			$captured = [
				'url'  => $url,
				'args' => $args,
			];

			return [
				'response' => [ 'code' => 200 ],
				'body'     => 'stub',
			];
		}
	);
	Monkey\Functions\when( 'wp_remote_get' )->alias(
		static function ( string $url, array $args ) use ( &$captured ): array {
			$captured = [
				'url'  => $url,
				'args' => $args,
			];

			return [
				'response' => [ 'code' => 200 ],
				'body'     => 'stub',
			];
		}
	);
	Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn(
		(string) wp_json_encode(
			[
				'success' => true,
				'data'    => $data,
			]
		)
	);
}

it(
	'creates checkout sessions with plan and return URLs',
	function () {
		$captured = [];

		rf_capture_billing_request( $captured, [ 'checkout_url' => 'https://billing.example/session' ] );

		$data = ( new CloudClient( 'https://cloud.example.com', 'key' ) )->billingCheckout(
			'cloud',
			'https://site.example/wp-admin/admin.php?page=relevant-settings&billing=return',
			'https://site.example/wp-admin/admin.php?page=relevant-settings&billing=cancel'
		);

		expect( $data['checkout_url'] )->toBe( 'https://billing.example/session' );
		expect( $captured['url'] )->toBe( 'https://cloud.example.com/api/v1/billing/checkout' );

		$payload = json_decode( (string) $captured['args']['body'], true );
		expect( $payload['plan'] )->toBe( 'cloud' );
		expect( $payload['success_url'] )->toContain( 'billing=return' );
	}
);

it(
	'fetches the hosted portal URL',
	function () {
		$captured = [];

		rf_capture_billing_request( $captured, [ 'portal_url' => 'https://billing.example/portal' ] );

		$data = ( new CloudClient( 'https://cloud.example.com', 'key' ) )->billingPortal( 'https://site.example/goback' );

		expect( $data['portal_url'] )->toBe( 'https://billing.example/portal' );
		expect( $captured['url'] )->toContain( '/api/v1/billing/portal?return_url=' );
		expect( $captured['url'] )->toContain( rawurlencode( 'https://site.example/goback' ) );
	}
);

it(
	'reads the subscription snapshot',
	function () {
		$captured = [];

		rf_capture_billing_request(
			$captured,
			[
				'subscription' => [
					'plan'     => 'cloud',
					'status'   => 'active',
					'entitled' => true,
				],
			]
		);

		$data = ( new CloudClient( 'https://cloud.example.com', 'key' ) )->billingStatus();

		expect( $data['subscription']['plan'] )->toBe( 'cloud' );
		expect( $captured['url'] )->toBe( 'https://cloud.example.com/api/v1/billing/status' );
	}
);
