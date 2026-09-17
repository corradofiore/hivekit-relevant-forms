<?php
/**
 * CloudStorageProvider unit tests (Phase 6, doc §28–32).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Uploads\Contracts\StoredObject;
use RelevantForms\Uploads\Contracts\UploadRequest;
use RelevantForms\Uploads\Storage\CloudStorageProvider;

beforeEach(
	function () {
		Monkey\Functions\when( 'get_option' )->justReturn(
			[
				'cloud_url'     => 'https://cloud.example.com',
				'cloud_api_key' => 'site-key',
			]
		);
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );
	}
);

it(
	'authorizes managed uploads through the Cloud',
	function () {
		$captured = null;

		Monkey\Functions\when( 'wp_remote_post' )->alias(
			static function ( string $url, array $args ) use ( &$captured ): array {
				$captured = [
					'url'  => $url,
					'args' => $args,
				];

				return [
					'response' => [ 'code' => 201 ],
					'body'     => 'stub',
				];
			}
		);
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 201 );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			(string) wp_json_encode(
				[
					'data' => [
						'upload_id'  => 5,
						'object_key' => 'sites/abcd1234/forms/1/uploads/x.pdf',
						'method'     => 'PUT',
						'url'        => 'https://managed.example/put?X-Amz-Signature=put',
						'headers'    => [ 'Content-Type' => 'application/pdf' ],
						'expires_in' => 900,
					],
				]
			)
		);

		$authorization = ( new CloudStorageProvider() )->createUploadAuthorization(
			new UploadRequest( 'sites/abcd1234/forms/1/uploads/x.pdf', 1000000, 'application/pdf', 3600, 1, 'report.pdf', 1234 )
		);

		expect( $authorization->method )->toBe( 'PUT' );
		expect( $authorization->url )->toBe( 'https://managed.example/put?X-Amz-Signature=put' );
		expect( $authorization->headers )->toBe( [ 'Content-Type' => 'application/pdf' ] );
		expect( $captured['url'] )->toBe( 'https://cloud.example.com/api/v1/uploads/authorize' );

		$payload = json_decode( (string) $captured['args']['body'], true );
		expect( $payload['filename'] )->toBe( 'report.pdf' );
		expect( $payload['size'] )->toBe( 1234 );
	}
);

it(
	'throws when the Cloud cannot authorize the upload',
	function () {
		Monkey\Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 503 ] ] );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );

		expect(
			fn () => ( new CloudStorageProvider() )->createUploadAuthorization(
				new UploadRequest( 'sites/abcd1234/forms/1/uploads/x.pdf', 1000, 'application/pdf' )
			)
		)->toThrow( RuntimeException::class );
	}
);

it(
	'verifies completion and reports readiness',
	function () {
		Monkey\Functions\when( 'wp_remote_post' )->justReturn(
			[
				'response' => [ 'code' => 200 ],
				'body'     => 'stub',
			]
		);
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"data":{"status":"uploaded","size_bytes":2048}}' );

		expect(
			( new CloudStorageProvider() )->exists( new StoredObject( 'sites/abcd1234/forms/1/uploads/x.pdf', 'local' ) )
		)->toBeTrue();
	}
);

it(
	'returns signed downloads only when the Cloud reports one',
	function () {
		Monkey\Functions\when( 'wp_remote_get' )->justReturn(
			[
				'response' => [ 'code' => 200 ],
				'body'     => 'stub',
			]
		);
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"data":{"status":"clean","download_url":"https://managed.example/get?X-Amz-Signature=get"}}' );

		$download = ( new CloudStorageProvider() )->getDownloadAuthorization(
			new StoredObject( 'sites/abcd1234/forms/1/uploads/x.pdf', 'local' )
		);

		expect( $download->url )->toContain( 'X-Amz-Signature=get' );
	}
);

it(
	'throws when no signed download URL is available',
	function () {
		Monkey\Functions\when( 'wp_remote_get' )->justReturn(
			[
				'response' => [ 'code' => 200 ],
				'body'     => 'stub',
			]
		);
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"data":{"status":"uploaded","download_url":""}}' );

		expect(
			fn () => ( new CloudStorageProvider() )->getDownloadAuthorization(
				new StoredObject( 'sites/abcd1234/forms/1/uploads/x.pdf', 'local' )
			)
		)->toThrow( RuntimeException::class );
	}
);

it(
	'deletes managed objects through the Cloud',
	function () {
		$captured = null;

		Monkey\Functions\when( 'wp_remote_request' )->alias(
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
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"data":{"status":"expired"}}' );

		expect(
			( new CloudStorageProvider() )->delete( new StoredObject( 'sites/abcd1234/forms/1/uploads/x.pdf', 'local' ) )
		)->toBeTrue();

		expect( $captured['args']['method'] )->toBe( 'DELETE' );
		expect( $captured['url'] )->toContain( '/api/v1/uploads?object_key=' );
	}
);
