<?php
/**
 * ClamAvScanner unit tests via a fake subclass (no real scanner needed).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use Brain\Monkey;
use SentinelForms\Uploads\Contracts\StoredObject;
use SentinelForms\Uploads\Scanning\ClamAvScanner;
use SentinelForms\Uploads\Scanning\MalwareScanResult;

/**
 * Fake scanner with controlled binary detection and command output.
 */
final class FakeClamAv extends ClamAvScanner {

	/**
	 * Whether the binary resolves.
	 *
	 * @var bool
	 */
	public bool $available = true;

	/**
	 * Next command result: [exitCode, outputLines].
	 *
	 * @var array{0: int, 1: array<int,string>}
	 */
	public array $next_run = [ 0, [] ];

	/**
	 * {@inheritDoc}
	 */
	protected function resolveBinary(): string {
		return $this->available ? '/usr/bin/clamscan' : '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function runCommand( string $command ): array {
		return $this->next_run;
	}
}

beforeEach(
	function () {
		Monkey\Functions\when( 'get_option' )->justReturn( [] );
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );
		Monkey\Functions\when( 'sanitize_text_field' )->returnArg();
	}
);

it(
	'reports unavailable when the binary cannot be located',
	function () {
		$scanner            = new FakeClamAv();
		$scanner->available = false;

		expect( $scanner->isAvailable() )->toBeFalse();
		expect( $scanner->scan( new StoredObject( 'x' ), '/tmp/nope' )->outcome )->toBe( MalwareScanResult::UNSUPPORTED );
	}
);

it(
	'detects the binary from PATH',
	function () {
		$scanner = new FakeClamAv();

		expect( $scanner->isAvailable() )->toBeTrue();
		expect( $scanner->name() )->toBe( 'clamav' );
	}
);

it(
	'reports clean on exit code 0',
	function () {
		$scanner = new FakeClamAv();
		$path    = tempnam( sys_get_temp_dir(), 'sf-clean-' );

		expect( $path )->not->toBeFalse();

		$scanner->next_run = [ 0, [ '/tmp/f.pdf: OK' ] ];

		$result = $scanner->scan( new StoredObject( 'x' ), (string) $path );

		expect( $result->isClean() )->toBeTrue();

		unlink( (string) $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture cleanup.
	}
);

it(
	'reports infected with the detection reference on exit code 1',
	function () {
		$scanner = new FakeClamAv();
		$path    = tempnam( sys_get_temp_dir(), 'sf-infect-' );

		expect( $path )->not->toBeFalse();

		$scanner->next_run = [ 1, [ '/tmp/f.pdf: Eicar-Test-Signature FOUND' ] ];

		$result = $scanner->scan( new StoredObject( 'x' ), (string) $path );

		expect( $result->isInfected() )->toBeTrue();
		expect( $result->reference )->toBe( 'Eicar-Test-Signature' );

		unlink( (string) $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture cleanup.
	}
);

it(
	'reports a scan error on any other exit code',
	function () {
		$scanner = new FakeClamAv();
		$path    = tempnam( sys_get_temp_dir(), 'sf-error-' );

		expect( $path )->not->toBeFalse();

		$scanner->next_run = [ 2, [ 'ERROR: Database missing' ] ];

		$result = $scanner->scan( new StoredObject( 'x' ), (string) $path );

		expect( $result->outcome )->toBe( MalwareScanResult::ERROR );

		unlink( (string) $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture cleanup.
	}
);
