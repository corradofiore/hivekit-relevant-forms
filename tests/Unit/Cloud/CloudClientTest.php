<?php
/**
 * CloudClient unit tests (HTTP stubbed).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Cloud\CloudClient;

/**
 * Captured request.
 *
 * @var array<string, mixed>|null
 */
$GLOBALS['rf_cloud_last_request'] = null;

beforeEach(
	function () {
		$GLOBALS['rf_cloud_last_request'] = null;

		Monkey\Functions\when( 'wp_json_encode' )->alias(
			static fn ( mixed $data ): string => (string) json_encode( $data ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure unit test stub.
		);

		Monkey\Functions\when( 'wp_remote_post' )->alias(
			static function ( string $url, array $args ): array {
				$GLOBALS['rf_cloud_last_request'] = [
					'url'  => $url,
					'args' => $args,
				];

				return [
					'response' => [ 'code' => 201 ],
					'body'     => '{"success":true,"data":{"site_id":"site-uuid-1","status":"active","capabilities":{"deep_scan":true}}}',
				];
			}
		);

		Monkey\Functions\when( 'wp_remote_get' )->alias(
			static function ( string $url, array $args ): array {
				$GLOBALS['rf_cloud_last_request'] = [
					'url'  => $url,
					'args' => $args,
				];

				return [
					'response' => [ 'code' => 200 ],
					'body'     => '{"success":true,"data":{"usage":{"scans":4},"allowances":{"scans":250}}}',
				];
			}
		);

		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( array $response ): int => (int) ( $response['response']['code'] ?? 0 )
		);
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( array $response ): string => (string) ( $response['body'] ?? '' )
		);
	}
);

it(
	'connects with bearer auth and the expected endpoint',
	function () {
		$client = new CloudClient( 'https://cloud.example.com', 'site-key-1' );

		$data = $client->connect( 'https://a.example', 'Acme' );

		expect( $data )->toBeArray();
		expect( $data['site_id'] ?? '' )->toBe( 'site-uuid-1' );

		$request = $GLOBALS['rf_cloud_last_request'];
		expect( $request['url'] )->toBe( 'https://cloud.example.com/api/v1/sites/connect' );
		expect( $request['args']['headers']['Authorization'] ?? '' )->toBe( 'Bearer site-key-1' );

		$body = json_decode( (string) $request['args']['body'], true );
		expect( $body['site_url'] ?? '' )->toBe( 'https://a.example' );
	}
);

it(
	'fetches usage from the expected endpoint',
	function () {
		$client = new CloudClient( 'https://cloud.example.com', 'site-key-1' );

		$data = $client->usage();

		expect( $data['usage']['scans'] ?? 0 )->toBe( 4 );
		expect( $GLOBALS['rf_cloud_last_request']['url'] )->toBe( 'https://cloud.example.com/api/v1/usage' );
	}
);
