<?php
/**
 * Deterministic attention score (doc §19).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Ai;

/**
 * Application-level business score. Computed locally from validated
 * classification values — never asked from the model.
 */
final class AttentionScore {

	/**
	 * legitimacy * 0.35 + relevance * 0.35 + urgency * 0.30, as 0–100.
	 */
	public static function calculate( float $spamProbability, float $relevance, float $urgency ): int {
		$legitimacy = 1.0 - max( 0.0, min( 1.0, $spamProbability ) );

		$score = $legitimacy * 0.35
			+ max( 0.0, min( 1.0, $relevance ) ) * 0.35
			+ max( 0.0, min( 1.0, $urgency ) ) * 0.30;

		return (int) round( max( 0.0, min( 1.0, $score ) ) * 100 );
	}

	/**
	 * Priority threshold (doc §20): 85+ deserves priority attention.
	 * One source for both the local score gate and the SMS default.
	 */
	public const PRIORITY_THRESHOLD = 85;

	/**
	 * Whether the score deserves priority attention.
	 */
	public static function isPriority( int $score ): bool {
		return $score >= self::PRIORITY_THRESHOLD;
	}
}
