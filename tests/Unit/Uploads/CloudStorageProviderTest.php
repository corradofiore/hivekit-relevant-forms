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
		Monkey\Functions\when( 'admin_url' )->justReturn( 'https://wp.example/wp-admin/admin-post.php' );
		Monkey\Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
	}
);

it(
	'relays uploads through the authenticated WordPress endpoint',
	function () {
		$authorization = ( new CloudStorageProvider() )->createUploadAuthorization(
			new UploadRequest( 'sites/abcd1234/forms/1/uploads/x.pdf', 1000000, 'application/pdf', 3600, 1, 'report.pdf', 1234 )
		);

		expect( $authorization->method )->toBe( 'POST' );
		expect( $authorization->url )->toContain( 'admin-post.php' );
		expect( $authorization->url )->toContain( 'action=relevant_forms_local_upload' );
		expect( $authorization->objectKey )->toBe( 'sites/abcd1234/forms/1/uploads/x.pdf' );
	}
);

it(
	'verifies uploaded state through the Cloud API',
	function () {
		Monkey\Functions\when( 'wp_remote_get' )->justReturn(
			[
				'response' => [ 'code' => 200 ],
				'body'     => 'stub',
			]
		);
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"data":{"status":"uploaded","size_bytes":2048}}' );

		expect(
			( new CloudStorageProvider() )->exists( new StoredObject( 'sites/abcd1234/forms/1/uploads/x.pdf', 'cloud' ) )
		)->toBeTrue();
	}
);

it(
	'reports objects as absent until the upload landed',
	function () {
		Monkey\Functions\when( 'wp_remote_get' )->justReturn(
			[
				'response' => [ 'code' => 200 ],
				'body'     => 'stub',
			]
		);
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"data":{"status":"pending"}}' );

		expect(
			( new CloudStorageProvider() )->exists( new StoredObject( 'sites/abcd1234/forms/1/uploads/x.pdf', 'cloud' ) )
		)->toBeFalse();
	}
);

it(
	'keeps downloads behind the authenticated WordPress proxy',
	function () {
		$download = ( new CloudStorageProvider() )->getDownloadAuthorization(
			new StoredObject( 'sites/abcd1234/forms/1/uploads/x.pdf', 'cloud' )
		);

		expect( $download->url )->toContain( 'admin-post.php' );
		expect( $download->url )->toContain( 'action=relevant_forms_download_upload' );
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
			( new CloudStorageProvider() )->delete( new StoredObject( 'sites/abcd1234/forms/1/uploads/x.pdf', 'cloud' ) )
		)->toBeTrue();

		expect( $captured['args']['method'] )->toBe( 'DELETE' );
		expect( $captured['url'] )->toContain( '/api/v1/uploads?object_key=' );
	}
);
