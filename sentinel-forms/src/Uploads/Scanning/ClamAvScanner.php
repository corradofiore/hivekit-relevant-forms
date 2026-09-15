<?php
/**
 * ClamAV scanner: first scanning implementation (doc §36, Phase 3).
 *
 * Uses the local clamscan binary. Path resolution order:
 * SENTINEL_CLAMSCAN_PATH constant → sentinel_forms_settings
 * scan_clamav_path option → sentinel_forms_clamav_path filter
 * → PATH lookup.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Scanning;

use SentinelForms\Support\Logger;
use SentinelForms\Uploads\Contracts\StoredObject;

/**
 * Scans local file copies with ClamAV's clamscan command-line scanner.
 * Not final so installations can extend path resolution or invocation.
 */
class ClamAvScanner implements MalwareScannerInterface {

	/**
	 * Resolved binary path cache (null = not yet resolved).
	 *
	 * @var string|null
	 */
	private ?string $binary = null;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'clamav';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		return '' !== $this->binaryPath();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param StoredObject $stored_object Object reference (metadata only).
	 */
	public function scan( StoredObject $stored_object, string $localPath ): MalwareScanResult {
		if ( ! $this->isAvailable() ) {
			Logger::warning( 'ClamAV is not available; upload remains quarantined.' );

			return MalwareScanResult::unsupported( $this->name() );
		}

		if ( ! is_file( $localPath ) ) {
			return MalwareScanResult::error( $this->name(), __( 'Local copy is missing.', 'sentinel-forms' ) );
		}

		$command = sprintf(
			'%s --no-summary --max-filesize=100M --max-scansize=100M %s 2>&1',
			escapeshellcmd( $this->binaryPath() ),
			escapeshellarg( $localPath )
		);

		[ $exitCode, $output ] = $this->runCommand( $command );

		$raw = implode( "\n", $output );

		if ( 0 === $exitCode ) {
			return MalwareScanResult::clean( $this->name() );
		}

		if ( 1 === $exitCode ) {
			$reference = $this->detectionReference( $raw );

			return MalwareScanResult::infected( $this->name(), $reference );
		}

		Logger::warning(
			'ClamAV scan failed',
			[
				'exit_code' => $exitCode,
			]
		);

		return MalwareScanResult::error( $this->name(), __( 'The scanner reported an error.', 'sentinel-forms' ) );
	}

	/**
	 * Cached, lazily resolved binary path.
	 */
	private function binaryPath(): string {
		if ( null === $this->binary ) {
			$this->binary = $this->resolveBinary();
		}

		return $this->binary;
	}

	/**
	 * Run a shell command and capture output/exit code.
	 *
	 * Test seam: subclasses may override.
	 *
	 * @return array{0: int, 1: array<int,string>} Exit code and output lines.
	 */
	protected function runCommand( string $command ): array {
		$output   = [];
		$exitCode = 1;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- running the local ClamAV scanner is the feature.
		exec( $command, $output, $exitCode );

		return [ $exitCode, $output ];
	}

	/**
	 * Resolve the clamscan binary path.
	 *
	 * Test seam: subclasses may override.
	 */
	protected function resolveBinary(): string {
		$constant = defined( 'SENTINEL_CLAMSCAN_PATH' ) ? (string) SENTINEL_CLAMSCAN_PATH : '';

		if ( '' !== $constant && is_file( $constant ) ) {
			return $constant;
		}

		$settings = (array) get_option( 'sentinel_forms_settings', [] );
		$option   = (string) ( $settings['scan_clamav_path'] ?? '' );

		if ( '' !== $option && is_file( $option ) ) {
			return $option;
		}

		$path = apply_filters( 'sentinel_forms_clamav_path', '' );

		if ( is_string( $path ) && '' !== $path && is_file( $path ) ) {
			return $path;
		}

		$found = [];
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- locating the local scanner binary.
		exec( 'command -v clamscan 2>/dev/null', $found );

		$located = (string) ( $found[0] ?? '' );

		return '' !== $located && is_file( $located ) ? $located : '';
	}

	/**
	 * Extract a detection signature from clamscan output.
	 *
	 * Typical line: `/path/file.pdf: Eicar-Test-Signature FOUND`.
	 */
	private function detectionReference( string $output ): string {
		if ( preg_match( '/([A-Za-z0-9][A-Za-z0-9._\-\/]*)\s+(?:FOUND|UNOFFICIAL)\b/', $output, $matches ) ) {
			return (string) $matches[1];
		}

		return 'unknown';
	}
}
