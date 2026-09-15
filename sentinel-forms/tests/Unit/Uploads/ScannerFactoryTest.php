<?php
/**
 * Scanner factory unit tests (T2 realignment).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use Brain\Monkey;
use SentinelForms\Uploads\Contracts\StoredObject;
use SentinelForms\Uploads\Scanning\BasicPhpScanner;
use SentinelForms\Uploads\Scanning\MalwareScanResult;
use SentinelForms\Uploads\Scanning\MalwareScannerInterface;
use SentinelForms\Uploads\Scanning\ScannerFactory;

beforeEach(
	function () {
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );
	}
);

it(
	'ships only the pure-PHP basic scanner in Core',
	function () {
		expect( ScannerFactory::make() )->toBeInstanceOf( BasicPhpScanner::class );
		expect( ScannerFactory::make()->name() )->toBe( 'php-basic' );
	}
);

it(
	'lets extensions register a deep local scanner',
	function () {
		Monkey\Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, mixed $value ): mixed {
				if ( 'sentinel_forms_malware_scanner' !== $hook ) {
					return $value;
				}

				return new class() implements MalwareScannerInterface
				{
					public function name(): string {
						return 'pro-clamav';
					}

					public function isAvailable(): bool {
						return true;
					}

					public function scan( StoredObject $stored_object, string $local_path ): MalwareScanResult {
						unset( $stored_object, $local_path );

						return MalwareScanResult::clean( 'pro-clamav' );
					}
				};
			}
		);

		expect( ScannerFactory::make()->name() )->toBe( 'pro-clamav' );
	}
);
