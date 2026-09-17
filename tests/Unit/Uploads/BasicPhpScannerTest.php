<?php
/**
 * BasicPhpScanner unit tests (Stage 1).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Uploads\Contracts\StoredObject;
use RelevantForms\Uploads\Scanning\BasicPhpScanner;
use RelevantForms\Uploads\Scanning\MalwareScanResult;

beforeEach(
	function () {
		Monkey\Functions\when( 'sanitize_text_field' )->returnArg();
	}
);

/**
 * Write content to a temp file and return its path.
 */
function rf_basic_fixture( string $content ): string {
	$path = tempnam( sys_get_temp_dir(), 'rf-basic-' );
	file_put_contents( (string) $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

	return (string) $path;
}

it(
	'flags the EICAR test signature as infected',
	function () {
		$scanner = new BasicPhpScanner();
		$eicar   = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
		$path    = rf_basic_fixture( 'hello ' . $eicar . ' world' );

		$result = $scanner->scan( new StoredObject( 'x' ), $path );

		expect( $result->isInfected() )->toBeTrue();
		expect( $result->reference )->toBe( 'EICAR-Test-Signature' );

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture cleanup.
	}
);

it(
	'finds the EICAR signature across chunk boundaries',
	function () {
		$scanner = new BasicPhpScanner();
		$eicar   = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
		// 1 MB of padding, then EICAR; both fall in different stream chunks.
		$path = rf_basic_fixture( str_repeat( 'a', 1048576 - 16 ) . $eicar );

		$result = $scanner->scan( new StoredObject( 'x' ), $path );

		expect( $result->isInfected() )->toBeTrue();

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture cleanup.
	}
);

it(
	'flags dangerous markers in text content as suspicious',
	function () {
		$scanner = new BasicPhpScanner();
		$path    = rf_basic_fixture( '<html><body><script>alert(1)</script></body></html>' );

		$result = $scanner->scan( new StoredObject( 'x' ), $path );

		expect( $result->outcome )->toBe( MalwareScanResult::SUSPICIOUS );

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture cleanup.
	}
);

it(
	'declares binary documents clean for the basic stage',
	function () {
		$scanner = new BasicPhpScanner();
		$path    = rf_basic_fixture( "\x89PNG\r\n\x1a\n" . str_repeat( "\0", 64 ) );

		$result = $scanner->scan( new StoredObject( 'x' ), $path );

		expect( $result->isClean() )->toBeTrue();

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture cleanup.
	}
);

it(
	'declares plain text without markers clean',
	function () {
		$scanner = new BasicPhpScanner();
		$path    = rf_basic_fixture( 'Just an ordinary message with words like evaluation and scripts.' );

		$result = $scanner->scan( new StoredObject( 'x' ), $path );

		expect( $result->isClean() )->toBeTrue();

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture cleanup.
	}
);

it(
	'flags zero-byte files as suspicious',
	function () {
		$scanner = new BasicPhpScanner();
		$path    = rf_basic_fixture( '' );

		$result = $scanner->scan( new StoredObject( 'x' ), $path );

		expect( $result->outcome )->toBe( MalwareScanResult::SUSPICIOUS );

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture cleanup.
	}
);
