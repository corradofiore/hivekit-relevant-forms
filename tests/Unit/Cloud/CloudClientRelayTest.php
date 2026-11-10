<?php
/**
 * CloudClient managed-upload relay tests.
 *
 * The relay posts the local temp file with wp_remote_post() and downloads the
 * managed object with wp_remote_get() in streaming mode, so neither direction
 * buffers the whole payload in PHP memory.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Cloud\CloudClient;

/**
 * Request captured from wp_remote_post() / wp_remote_get().
 *
 * @var array{url: string, args: array<string, mixed>}|null
 */
$GLOBALS['rf_relay_request'] = null;

beforeEach(
	function () {
		$GLOBALS['rf_relay_request'] = null;

		// No transport override: exercise the real HTTP API path.
		Monkey\Functions\when( 'apply_filters' )->justReturn( null );

		// The client resolves these in its own namespace, so they can be
		// stubbed here without touching the disk or the network.
		Monkey\Functions\when( 'RelevantForms\Cloud\is_file' )->justReturn( true );
		Monkey\Functions\when( 'RelevantForms\Cloud\filesize' )->justReturn( 22 );
		Monkey\Functions\when( 'RelevantForms\Cloud\file_get_contents' )->justReturn( str_repeat( 'a', 22 ) );

		Monkey\Functions\when( 'wp_remote_post' )->alias(
			static function ( string $url, array $args ): array {
				$GLOBALS['rf_relay_request'] = [
					'url'  => $url,
					'args' => $args,
				];

				return [
					'response' => [ 'code' => 201 ],
					'body'     => '{"success":true,"data":{"status":"uploaded"}}',
				];
			}
		);
		Monkey\Functions\when( 'wp_remote_get' )->alias(
			static function ( string $url, array $args ): array {
				$GLOBALS['rf_relay_request'] = [
					'url'  => $url,
					'args' => $args,
				];

				return [ 'response' => [ 'code' => 200 ] ];
			}
		);
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( array $response ): int {
				return (int) ( $response['response']['code'] ?? 0 );
			}
		);
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( array $response ): string {
				return (string) ( $response['body'] ?? '' );
			}
		);
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );
	}
);

it(
	'relays a managed upload through the WordPress HTTP API',
	function () {
		$result = ( new CloudClient( 'https://cloud.example.com/api/v1', 'site-key' ) )
			->uploadManagedFile( 'sites/abcd1234/forms/22/uploads/x.txt', 'x.txt', 'text/plain', '/tmp/relay-payload.txt' );

		$request = $GLOBALS['rf_relay_request'];

		expect( $request['url'] )->toBe( 'https://cloud.example.com/api/v1/uploads' );
		expect( $request['args']['headers']['Authorization'] )->toBe( 'Bearer site-key' );
		expect( $request['args']['headers']['Content-Type'] )->toBe( 'application/octet-stream' );
		expect( $request['args']['headers']['X-Relevant-Object-Key'] )->toBe( 'sites/abcd1234/forms/22/uploads/x.txt' );
		expect( $request['args']['body'] )->toBe( str_repeat( 'a', 22 ) );
		expect( $result['status'] )->toBe( 201 );
		expect( $result['data']['status'] )->toBe( 'uploaded' );
	}
);

it(
	'streams a managed download straight to the sink file',
	function () {
		$ok = ( new CloudClient( 'https://cloud.example.com/api/v1', 'site-key' ) )
			->downloadManagedContent( 'sites/abcd1234/forms/22/x y.txt', '/tmp/sink.bin' );

		$request = $GLOBALS['rf_relay_request'];

		expect( $ok )->toBeTrue();
		expect( $request['url'] )->toBe( 'https://cloud.example.com/api/v1/uploads/sites/abcd1234/forms/22/x%20y.txt/content' );
		expect( $request['args']['stream'] )->toBeTrue();
		expect( $request['args']['filename'] )->toBe( '/tmp/sink.bin' );
		expect( $request['args']['headers']['Accept'] )->toBe( 'application/octet-stream' );
	}
);
