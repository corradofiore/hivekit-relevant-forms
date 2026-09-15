<?php
/**
 * Stage 1 basic scanner: pure PHP, bounded memory, no external services.
 *
 * Designed for shared hosting where installing ClamAV or reaching a
 * backend is not guaranteed. Stage 2 (deep scanning) runs in our
 * backend queue. This scanner never reads a whole file into memory:
 * it streams fixed-size chunks.
 *
 * Checks (conservative, doc §69 — uncertain stays quarantined):
 * - EICAR test signature → INFECTED (well-defined industry test vector).
 * - Dangerous payload markers in text-like content → SUSPICIOUS.
 * - Zero-byte or unreadable files → SUSPICIOUS.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Scanning;

use SentinelForms\Uploads\Contracts\StoredObject;

/**
 * Pure-PHP pre-filter for uploaded objects (Stage 1).
 */
class BasicPhpScanner implements MalwareScannerInterface {

	/**
	 * Standard EICAR test string (68 chars, used by AV vendors).
	 */
	private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

	/**
	 * Marker patterns that make a file suspicious. Bounded and
	 * conservative: these appear in executable-ish payloads, not in
	 * ordinary office documents.
	 *
	 * @var array<int, string>
	 */
	private const MARKERS = [
		'<?php',
		'<?=',
		'<script',
		'base64_decode(',
		'eval(',
		'shell_exec(',
		'powershell.exe',
		'cmd.exe /c',
	];

	/**
	 * Chunk size for streaming reads (1 MB).
	 */
	private const CHUNK = 1048576;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'php-basic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function scan( StoredObject $stored_object, string $localPath ): MalwareScanResult {
		if ( ! is_file( $localPath ) ) {
			return MalwareScanResult::error( $this->name(), __( 'Local copy is missing.', 'sentinel-forms' ) );
		}

		if ( 0 === (int) filesize( $localPath ) ) {
			return MalwareScanResult::suspicious( $this->name(), 'zero-byte' );
		}

		$handle = fopen( $localPath, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming read for scanning.

		if ( false === $handle ) {
			return MalwareScanResult::suspicious( $this->name(), 'unreadable' );
		}

		$found     = null;
		$overlap   = max( strlen( self::EICAR ), 64 ) - 1;
		$carry     = '';
		$total     = 0;
		$binaryHit = false;

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming read for scanning.

			if ( false === $chunk || '' === $chunk ) {
				continue;
			}

			$buffer = $carry . $chunk;
			$carry  = substr( $buffer, -$overlap );

			// Binary detection: NUL bytes imply non-text content.
			if ( str_contains( $chunk, "\0" ) ) {
				$binaryHit = true;
			}

			if ( str_contains( $buffer, self::EICAR ) ) {
				$found = MalwareScanResult::infected( $this->name(), 'EICAR-Test-Signature' );
				break;
			}

			$total += strlen( $chunk );

			if ( $total > 5 * self::CHUNK ) {
				break; // Markers are only meaningful near the start of a file.
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming read for scanning.

		if ( null !== $found ) {
			return $found;
		}

		// Marker check only for text-like content: binary documents are
		// left to Stage 2 rather than pattern-matched.
		if ( $binaryHit ) {
			return MalwareScanResult::clean( $this->name() );
		}

		$marker = $this->findMarker( $localPath );

		return null !== $marker
			? MalwareScanResult::suspicious( $this->name(), $marker )
			: MalwareScanResult::clean( $this->name() );
	}

	/**
	 * Find the first dangerous marker in the leading part of the file.
	 */
	private function findMarker( string $localPath ): ?string {
		$handle = fopen( $localPath, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming read for scanning.

		if ( false === $handle ) {
			return 'unreadable';
		}

		$overlap = 64;
		$carry   = '';
		$total   = 0;
		$marker  = null;

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming read for scanning.

			if ( false === $chunk || '' === $chunk ) {
				continue;
			}

			$buffer = $carry . strtolower( $chunk );
			$carry  = substr( $buffer, -$overlap );

			foreach ( self::MARKERS as $candidate ) {
				if ( str_contains( $buffer, $candidate ) ) {
					$marker = $candidate;
					break 2;
				}
			}

			$total += strlen( $chunk );

			if ( $total > 2 * self::CHUNK ) {
				break;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming read for scanning.

		return $marker;
	}
}
