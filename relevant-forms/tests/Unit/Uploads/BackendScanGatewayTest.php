<?php
/**
 * BackendScanGateway unit tests: deep scanning uses the versioned Cloud
 * API base and the Cloud site key (no separate scan endpoint/key).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Uploads\Domain\Upload;
use RelevantForms\Uploads\Scanning\BackendScanGateway;
use RelevantForms\Uploads\Scanning\MalwareScanResult;

beforeEach(
	function () {
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( array $response ): int => (int) ( $response['response']['code'] ?? 0 )
		);
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( array $response ): string => (string) ( $response['body'] ?? '' )
		);
	}
);

it(
	'submits managed scans to the versioned Cloud endpoint',
	function () {
		Monkey\Functions\when( 'wp_remote_post' )->alias(
			static function ( string $url, array $args ): array {
				$GLOBALS['rf_scan_last_request'] = [
					'url'  => $url,
					'args' => $args,
				];

				return [
					'response' => [ 'code' => 202 ],
					'body'     => '{"success":true,"data":{"scan_request_id":"77"}}',
				];
			}
		);

		$upload = new Upload(
			1,
			2,
			'f_file',
			null,
			'managed',
			'cloud',
			'objects/x.pdf',
			'x.pdf',
			'pdf',
			'application/pdf',
			'application/pdf',
			123,
			str_repeat( 'a', 64 ),
			'quarantined',
			'pending',
			'',
			'',
			'',
			'',
			'',
			'',
			''
		);

		$id = ( new BackendScanGateway( 'https://cloud.example.com/api/v1', 'site-key' ) )->submitManaged( $upload );

		expect( $id )->toBe( '77' );
		expect( $GLOBALS['rf_scan_last_request']['url'] )->toBe( 'https://cloud.example.com/api/v1/uploads/scan' );
		expect( $GLOBALS['rf_scan_last_request']['args']['headers']['Authorization'] ?? '' )->toBe( 'Bearer site-key' );

		$body = json_decode( (string) $GLOBALS['rf_scan_last_request']['args']['body'], true );
		expect( $body['provider'] ?? '' )->toBe( 'managed' );
		expect( $body['object_key'] ?? '' )->toBe( 'objects/x.pdf' );
	}
);

it(
	'polls the versioned Cloud endpoint for scan results',
	function () {
		Monkey\Functions\when( 'wp_remote_get' )->alias(
			static function ( string $url, array $args ): array {
				$GLOBALS['rf_scan_last_request'] = [
					'url'  => $url,
					'args' => $args,
				];

				return [
					'response' => [ 'code' => 200 ],
					'body'     => '{"success":true,"data":{"status":"clean","reference":"ref-1"}}',
				];
			}
		);

		$result = ( new BackendScanGateway( 'https://cloud.example.com/api/v1', 'site-key' ) )->fetch( '42' );

		expect( $GLOBALS['rf_scan_last_request']['url'] )->toBe( 'https://cloud.example.com/api/v1/uploads/scan/42' );
		expect( $GLOBALS['rf_scan_last_request']['args']['headers']['Authorization'] ?? '' )->toBe( 'Bearer site-key' );
		expect( $result )->toBeInstanceOf( MalwareScanResult::class );
		expect( $result->outcome )->toBe( MalwareScanResult::CLEAN );
		expect( $result->engine )->toBe( 'backend' );
		expect( $result->reference )->toBe( 'ref-1' );
	}
);
