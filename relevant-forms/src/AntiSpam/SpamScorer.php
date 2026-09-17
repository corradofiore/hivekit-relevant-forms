<?php
/**
 * Local spam scoring (doc §19, §20, §69).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\AntiSpam;

use RelevantForms\Entries\Entry;

/**
 * Turns deterministic signals into a normalized 0–1 score and an entry
 * status. Conservative by design: uncertainty → suspicious, never deleted.
 */
final class SpamScorer {

	/**
	 * At or above: classified as spam (no notification).
	 */
	public const SPAM_THRESHOLD = 0.95;

	/**
	 * At or above (below spam): suspicious.
	 */
	public const SUSPICIOUS_THRESHOLD = 0.5;

	/**
	 * Weight per signal.
	 */
	private const WEIGHT_TIMING    = 0.4;
	private const WEIGHT_LINKS     = 0.4;
	private const WEIGHT_DUPLICATE = 0.5;
	private const WEIGHT_RATE      = 0.6;

	/**
	 * Score a signal bag to a 0–1 value.
	 */
	public function score( SpamSignals $signals ): float {
		$score = 0.0;

		// Bots fill forms instantly; humans rarely under the floor.
		if ( $signals->submissionTimeMs > 0 && $signals->submissionTimeMs < 1500 ) {
			$score += self::WEIGHT_TIMING;
		}

		if ( $signals->linksDetected > 3 ) {
			$score += self::WEIGHT_LINKS;
		}

		if ( $signals->duplicateCount > 0 ) {
			$score += self::WEIGHT_DUPLICATE;
		}

		if ( $signals->rateLimit > 0 && $signals->recentSubmissions >= $signals->rateLimit ) {
			$score += self::WEIGHT_RATE;
		}

		return min( 1.0, $score );
	}

	/**
	 * Map a score to an entry status.
	 */
	public function classify( float $score ): string {
		if ( $score >= self::SPAM_THRESHOLD ) {
			return Entry::STATUS_SPAM;
		}

		if ( $score >= self::SUSPICIOUS_THRESHOLD ) {
			return Entry::STATUS_SUSPICIOUS;
		}

		return Entry::STATUS_NEW;
	}
}
