<?php
/**
 * UploadPolicy unit tests.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use Brain\Monkey;
use SentinelForms\Uploads\Policy\UploadPolicy;

it(
	'applies the default policy when the field has none',
	function () {
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );

		$policy = UploadPolicy::fromDefinition(
			[
				'id'   => 'upload_1',
				'type' => 'file',
			]
		);

		expect( $policy->extensions )->toBe( UploadPolicy::DEFAULT_EXTENSIONS );
		expect( $policy->maxSize )->toBe( UploadPolicy::DEFAULT_MAX_SIZE );
		expect( $policy->maxFiles )->toBe( UploadPolicy::DEFAULT_MAX_FILES );
	}
);

it(
	'overrides defaults from the field definition',
	function () {
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );

		$policy = UploadPolicy::fromDefinition(
			[
				'id'            => 'upload_1',
				'type'          => 'file',
				'upload_policy' => [
					'allowed_ext' => [ 'PDF', 'csv' ],
					'max_size'    => 2097152,
					'max_files'   => 2,
				],
			]
		);

		expect( $policy->extensions )->toBe( [ 'pdf', 'csv' ] );
		expect( $policy->maxSize )->toBe( 2097152 );
		expect( $policy->maxFiles )->toBe( 2 );
	}
);

it(
	'falls back to defaults for zero values',
	function () {
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );

		$policy = UploadPolicy::fromDefinition(
			[
				'id'            => 'upload_1',
				'type'          => 'file',
				'upload_policy' => [
					'max_size'  => 0,
					'max_files' => 0,
				],
			]
		);

		expect( $policy->maxSize )->toBe( UploadPolicy::DEFAULT_MAX_SIZE );
		expect( $policy->maxFiles )->toBe( UploadPolicy::DEFAULT_MAX_FILES );
	}
);
