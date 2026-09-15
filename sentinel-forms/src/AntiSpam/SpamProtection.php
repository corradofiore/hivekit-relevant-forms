<?php
/**
 * Local anti-spam pipeline (doc §12): signals → score → entry status.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\AntiSpam;

/**
 * Orchestrates the deterministic checks and produces the final
 * signal bag, score and workflow status.
 */
final class SpamProtection {

	/**
	 * Default rate-limit window (10 minutes, doc §12).
	 */
	public const RATE_WINDOW_SECONDS = 600;

	/**
	 * Default duplicate window (10 minutes).
	 */
	public const DUPLICATE_WINDOW_SECONDS = 600;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->heuristics = new SpamHeuristics();
		$this->scorer     = new SpamScorer();
	}

	/**
	 * Heuristics.
	 *
	 * @var SpamHeuristics
	 */
	private SpamHeuristics $heuristics;

	/**
	 * Scorer.
	 *
	 * @var SpamScorer
	 */
	private SpamScorer $scorer;

	/**
	 * Evaluate a submission.
	 *
	 * @param int                  $formId    Owning form ID.
	 * @param array<string, mixed> $values    Sanitized field values.
	 * @param string               $ipHash    Privacy IP hash (never the raw IP).
	 * @param float|null           $startedAt Render timestamp (microtime).
	 * @return array{status: string, score: float, signals: SpamSignals, hash: string}
	 */
	public function evaluate( int $formId, array $values, string $ipHash, ?float $startedAt ): array {
		$rateLimit = (int) apply_filters( 'sentinel_forms_rate_limit', 5, $formId );

		$hash = $this->heuristics->submissionHash( $values );

		$signals = new SpamSignals(
			false,
			$this->heuristics->elapsedMs( $startedAt ),
			$this->heuristics->linksIn( $values ),
			$this->heuristics->duplicatesInWindow( $values, self::DUPLICATE_WINDOW_SECONDS ),
			$this->heuristics->recentFromIp( $ipHash, self::RATE_WINDOW_SECONDS ),
			$rateLimit,
		);

		$score = $this->scorer->score( $signals );

		$score = (float) apply_filters( 'sentinel_forms_spam_score', $score, $signals, $formId );
		$score = max( 0.0, min( 1.0, $score ) );

		$signals = SpamSignals::fromArray(
			array_merge( $signals->toArray(), [ 'spam_score' => $score ] )
		);

		return [
			'status'  => $this->scorer->classify( $score ),
			'score'   => $score,
			'signals' => $signals,
			'hash'    => $hash,
		];
	}
}
