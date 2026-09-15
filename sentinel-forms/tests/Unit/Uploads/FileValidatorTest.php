<?php
/**
 * FileValidator unit tests.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use Brain\Monkey;
use SentinelForms\Uploads\Policy\UploadPolicy;
use SentinelForms\Uploads\Validation\FileValidator;

beforeEach(
	function () {
		Monkey\Functions\when( 'wp_basename' )->alias( 'basename' );
		Monkey\Functions\when( 'sanitize_file_name' )->alias(
			static fn ( string $name ): string => preg_replace( '/[^a-zA-Z0-9._-]/', '-', $name ) ?? '',
		);
	}
);

it(
	'accepts declared metadata within the policy',
	function () {
		$validator = new FileValidator();
		$policy    = UploadPolicy::fromDefinition( [ 'type' => 'file' ] );

		expect( $validator->validateDeclared( $policy, 'report.pdf', 1024 ) )->toBeNull();
	}
);

it(
	'rejects oversized files',
	function () {
		$validator = new FileValidator();
		$policy    = UploadPolicy::fromDefinition(
			[
				'type'          => 'file',
				'upload_policy' => [ 'max_size' => 1024 ],
			]
		);

		expect( $validator->validateDeclared( $policy, 'report.pdf', 2048 ) )->not->toBeNull();
	}
);

it(
	'rejects disallowed or missing extensions',
	function () {
		$validator = new FileValidator();
		$policy    = UploadPolicy::fromDefinition(
			[
				'type'          => 'file',
				'upload_policy' => [ 'allowed_ext' => [ 'pdf' ] ],
			]
		);

		expect( $validator->validateDeclared( $policy, 'virus.exe', 10 ) )->not->toBeNull();
		expect( $validator->validateDeclared( $policy, 'noextension', 10 ) )->not->toBeNull();
	}
);

it(
	'sanitizes hostile filenames for display only',
	function () {
		$validator = new FileValidator();

		expect( $validator->sanitizeFilename( '../../etc/passwd' ) )->toBe( 'passwd' );
		expect( $validator->sanitizeFilename( 'hello world!.pdf' ) )->toBe( 'hello-world-.pdf' );
	}
);

it(
	'detects mime from magic bytes, not the filename',
	function () {
		$validator = new FileValidator();
		$path      = tempnam( sys_get_temp_dir(), 'sf-' );

		expect( $path )->not->toBeFalse();

		$png = "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . str_repeat( "\0", 13 ) . str_repeat( "\0", 4 );
		file_put_contents( $path, $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- pure unit test.

		expect( $validator->detectMime( (string) $path ) )->toBe( 'image/png' );

		unlink( (string) $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- pure unit test.
	}
);
