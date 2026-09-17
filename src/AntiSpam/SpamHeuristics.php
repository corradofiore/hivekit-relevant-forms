<?php
/**
 * Deterministic spam heuristics (doc §12, §3.2).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\AntiSpam;

use RelevantForms\Entries\EntryRepository;

/**
 * Pure local checks: timing, link density, duplicate and rate windows.
 * No external services; everything runs before (and without) AI.
 */
final class SpamHeuristics {

	/**
	 * Entry repository, created lazily so pure checks stay unit-testable.
	 *
	 * @var EntryRepository|null
	 */
	private ?EntryRepository $entries = null;

	/**
	 * Count URLs in the string values of a submission.
	 *
	 * @param array<string, mixed> $values Sanitized field values.
	 */
	public function linksIn( array $values ): int {
		$count = 0;

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			$count += (int) preg_match_all( '#https?://#i', $value );
		}

		return $count;
	}

	/**
	 * Render-to-submit elapsed time in milliseconds.
	 *
	 * @param float|null $startedAt Microtime captured at render.
	 */
	public function elapsedMs( ?float $startedAt ): int {
		if ( null === $startedAt || $startedAt <= 0.0 ) {
			return 0;
		}

		return max( 0, (int) round( ( microtime( true ) - $startedAt ) * 1000 ) );
	}

	/**
	 * Submissions from the same IP hash within the window.
	 */
	public function recentFromIp( string $ipHash, int $windowSeconds ): int {
		if ( '' === $ipHash ) {
			return 0;
		}

		return $this->repository()->countRecentByIp( $ipHash, $windowSeconds );
	}

	/**
	 * Identical submissions within the window.
	 *
	 * @param array<string, mixed> $values Sanitized field values.
	 */
	public function duplicatesInWindow( array $values, int $windowSeconds ): int {
		return $this->repository()->countDuplicates( $this->submissionHash( $values ), $windowSeconds );
	}

	/**
	 * Deterministic fingerprint of the submission content (no IP/PII).
	 *
	 * @param array<string, mixed> $values Sanitized field values.
	 */
	public function submissionHash( array $values ): string {
		$normalized = array_map(
			static fn ( mixed $value ): string => is_string( $value ) ? trim( $value ) : ( is_array( $value ) ? implode( ',', $value ) : (string) $value ),
			$values
		);
		ksort( $normalized );

		return hash( 'sha256', wp_json_encode( $normalized ) );
	}

	/**
	 * Lazily build the repository (keeps pure checks unit-testable).
	 */
	private function repository(): EntryRepository {
		if ( null === $this->entries ) {
			$this->entries = new EntryRepository();
		}

		return $this->entries;
	}
}
