<?php
/**
 * CloudClient managed-upload relay tests.
 *
 * The relay streams the file with cURL (CURLOPT_UPLOAD), which implies PUT.
 * The API route only accepts POST, so the method is forced back with
 * CURLOPT_CUSTOMREQUEST: without it every managed upload is answered with
 * "405 Method Not Allowed". These tests pin the method down.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Cloud\CloudClient;

/**
 * Options captured from the relay's curl_setopt_array() call.
 *
 * @var array<int, mixed>|null
 */
$GLOBALS['rf_relay_options'] = null;

/**
 * URL captured from the relay's curl_init() call.
 *
 * @var string|null
 */
$GLOBALS['rf_relay_url'] = null;

beforeEach(
	function () {
		$GLOBALS['rf_relay_options'] = null;
		$GLOBALS['rf_relay_url']     = null;

		// No transport override: exercise the real cURL relay path.
		Monkey\Functions\when( 'apply_filters' )->justReturn( null );

		// The client resolves these in its own namespace, so they can be
		// stubbed here without touching the disk or the network.
		Monkey\Functions\when( 'RelevantForms\Cloud\is_file' )->justReturn( true );
		Monkey\Functions\when( 'RelevantForms\Cloud\filesize' )->justReturn( 22 );
		Monkey\Functions\when( 'RelevantForms\Cloud\fopen' )->justReturn( new stdClass() );
		Monkey\Functions\when( 'RelevantForms\Cloud\fclose' )->justReturn( true );
		Monkey\Functions\when( 'RelevantForms\Cloud\curl_init' )->alias(
			static function ( string $url ): object {
				$GLOBALS['rf_relay_url'] = $url;

				return new stdClass();
			}
		);
		Monkey\Functions\when( 'RelevantForms\Cloud\curl_setopt_array' )->alias(
			static function ( object $handle, array $options ): bool {
				unset( $handle );

				$GLOBALS['rf_relay_options'] = $options;

				return true;
			}
		);
		Monkey\Functions\when( 'RelevantForms\Cloud\curl_exec' )->justReturn( '{"success":true,"data":{"status":"uploaded"}}' );
		Monkey\Functions\when( 'RelevantForms\Cloud\curl_getinfo' )->justReturn( 201 );
		Monkey\Functions\when( 'RelevantForms\Cloud\curl_error' )->justReturn( '' );
	}
);

it(
	'relays a managed upload as a POST that still streams the file',
	function () {
		$result = ( new CloudClient( 'https://cloud.example.com/api/v1', 'site-key' ) )
			->uploadManagedFile( 'sites/abcd1234/forms/22/uploads/x.txt', 'x.txt', 'text/plain', '/tmp/relay-payload.txt' );

		$options = $GLOBALS['rf_relay_options'];

		expect( $GLOBALS['rf_relay_url'] )->toBe( 'https://cloud.example.com/api/v1/uploads' );
		expect( $options[ CURLOPT_CUSTOMREQUEST ] )->toBe( 'POST' );
		expect( $options[ CURLOPT_UPLOAD ] )->toBeTrue();
		expect( $options[ CURLOPT_INFILESIZE ] )->toBe( 22 );
		expect( $result['status'] )->toBe( 201 );
	}
);
