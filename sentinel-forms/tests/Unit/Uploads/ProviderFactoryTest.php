<?php
/**
 * Storage provider factory unit tests (T1 realignment).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use Brain\Monkey;
use SentinelForms\Uploads\Contracts\DownloadAuthorization;
use SentinelForms\Uploads\Contracts\FileStorageProviderInterface;
use SentinelForms\Uploads\Contracts\StoredObject;
use SentinelForms\Uploads\Contracts\UploadAuthorization;
use SentinelForms\Uploads\Contracts\UploadRequest;
use SentinelForms\Uploads\Storage\LocalStorageProvider;
use SentinelForms\Uploads\Storage\ProviderFactory;

/**
 * Option store for settings/connection state.
 *
 * @var array<string, mixed>
 */
$GLOBALS['sf_provider_store'] = [];

beforeEach(
	function () {
		$GLOBALS['sf_provider_store'] = [];

		Monkey\Functions\when( 'get_option' )->alias(
			static function ( string $key, mixed $fallback = [] ): mixed {
				return $GLOBALS['sf_provider_store'][ $key ] ?? $fallback;
			}
		);
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );
		Monkey\Functions\when( 'do_action' )->justReturn( null );
	}
);

it(
	'defaults to local storage',
	function () {
		expect( ProviderFactory::make() )->toBeInstanceOf( LocalStorageProvider::class );
	}
);

it(
	'falls back to local when managed storage lacks consent',
	function () {
		$GLOBALS['sf_provider_store']['sentinel_forms_settings'] = [ 'storage_provider' => 'cloud' ];

		expect( ProviderFactory::make() )->toBeInstanceOf( LocalStorageProvider::class );
	}
);

it(
	'lets extensions supply a provider through the filter',
	function () {
		Monkey\Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, mixed $value ): mixed {
				if ( 'sentinel_forms_storage_provider' !== $hook ) {
					return $value;
				}

				return new class() implements FileStorageProviderInterface
				{
					public function name(): string {
						return 'byo-s3';
					}

					public function createUploadAuthorization( UploadRequest $request ): UploadAuthorization {
						unset( $request );

						return new UploadAuthorization( 'PUT', 'https://byo.example' );
					}

					public function getDownloadAuthorization( StoredObject $stored_object ): DownloadAuthorization {
						unset( $stored_object );

						return new DownloadAuthorization( 'https://byo.example/get' );
					}

					public function exists( StoredObject $stored_object ): bool {
						unset( $stored_object );

						return true;
					}

					public function delete( StoredObject $stored_object ): bool {
						unset( $stored_object );

						return true;
					}

					public function copyToTemporaryFile( StoredObject $stored_object ): ?string {
						unset( $stored_object );

						return null;
					}
				};
			}
		);

		expect( ProviderFactory::make()->name() )->toBe( 'byo-s3' );
	}
);
