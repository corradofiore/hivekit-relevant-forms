<?php
/**
 * Structured local anti-spam signals (doc §12).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\AntiSpam;

/**
 * Deterministic signals gathered about a submission, before any AI.
 */
final class SpamSignals {

	/**
	 * Constructor.
	 *
	 * @param bool $honeypotTriggered Honeypot field was filled.
	 * @param int  $submissionTimeMs  Render-to-submit elapsed time.
	 * @param int  $linksDetected     URLs found in text values.
	 * @param int  $duplicateCount    Identical submissions in the window.
	 * @param int  $recentSubmissions Submissions from this IP hash in the window.
	 * @param int  $rateLimit         Allowed submissions per window.
	 * @param float $spamScore        Final score (0–1), filled by the scorer.
	 */
	public function __construct(
		public readonly bool $honeypotTriggered = false,
		public readonly int $submissionTimeMs = 0,
		public readonly int $linksDetected = 0,
		public readonly int $duplicateCount = 0,
		public readonly int $recentSubmissions = 0,
		public readonly int $rateLimit = 5,
		public readonly float $spamScore = 0.0,
	) {}

	/**
	 * Structured array (JSON-friendly, doc §12).
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'honeypot_triggered' => $this->honeypotTriggered,
			'submission_time_ms' => $this->submissionTimeMs,
			'links_detected'     => $this->linksDetected,
			'duplicate_count'    => $this->duplicateCount,
			'recent_submissions' => $this->recentSubmissions,
			'rate_limit'         => $this->rateLimit,
			'spam_score'         => $this->spamScore,
		];
	}

	/**
	 * Rebuild from a stored array.
	 *
	 * @param array<string, mixed> $data Stored signal data.
	 */
	public static function fromArray( array $data ): self {
		return new self(
			! empty( $data['honeypot_triggered'] ),
			absint( $data['submission_time_ms'] ?? 0 ),
			absint( $data['links_detected'] ?? 0 ),
			absint( $data['duplicate_count'] ?? 0 ),
			absint( $data['recent_submissions'] ?? 0 ),
			absint( $data['rate_limit'] ?? 5 ),
			(float) ( $data['spam_score'] ?? 0.0 ),
		);
	}
}
