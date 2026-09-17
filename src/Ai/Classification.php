<?php
/**
 * Validated classification result (doc §16) plus local attention score.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Ai;

/**
 * Never trust model output: parse leniently, validate strictly.
 */
final class Classification {

	/**
	 * Allowed reason codes (doc §16).
	 *
	 * @var array<int, string>
	 */
	public const REASON_CODES = [
		'relevant_enquiry',
		'existing_customer',
		'unsolicited_marketing',
		'seo_solicitation',
		'irrelevant_request',
		'automated_spam',
		'abusive_content',
		'job_application',
		'supplier_contact',
		'unknown',
	];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $raw Server-validated classification payload.
	 */
	private function __construct(
		public readonly float $spamProbability,
		public readonly float $relevance,
		public readonly float $urgency,
		public readonly string $intent,
		public readonly string $category,
		public readonly string $summary,
		public readonly string $reasonCode,
		public readonly int $attentionScore,
		public readonly array $raw,
	) {}

	/**
	 * Build from a Cloud response, or null when invalid.
	 *
	 * @param array<string, mixed> $data Classification data.
	 */
	public static function fromArray( array $data ): ?self {
		foreach ( [ 'spam_probability', 'relevance', 'urgency' ] as $score ) {
			if ( ! isset( $data[ $score ] ) || ! is_numeric( $data[ $score ] ) ) {
				return null;
			}

			$value = (float) $data[ $score ];

			if ( $value < 0.0 || $value > 1.0 ) {
				return null;
			}
		}

		$reasonCode = (string) ( $data['reason_code'] ?? 'unknown' );

		if ( ! in_array( $reasonCode, self::REASON_CODES, true ) ) {
			$reasonCode = 'unknown';
		}

		$spam      = round( (float) $data['spam_probability'], 4 );
		$relevance = round( (float) $data['relevance'], 4 );
		$urgency   = round( (float) $data['urgency'], 4 );

		return new self(
			$spam,
			$relevance,
			$urgency,
			mb_substr( trim( (string) ( $data['intent'] ?? '' ) ), 0, 64 ),
			mb_substr( trim( (string) ( $data['category'] ?? '' ) ), 0, 64 ),
			// A 5-7 word subject phrase; the cap is the safety net.
			mb_substr( trim( (string) ( $data['summary'] ?? '' ) ), 0, 120 ),
			$reasonCode,
			AttentionScore::calculate( $spam, $relevance, $urgency ),
			$data,
		);
	}

	/**
	 * Payload stored in entry meta and shown in the admin.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array_merge(
			$this->raw,
			[
				'schema_version'   => 1,
				'spam_probability' => $this->spamProbability,
				'relevance'        => $this->relevance,
				'urgency'          => $this->urgency,
				'intent'           => $this->intent,
				'category'         => $this->category,
				'summary'          => $this->summary,
				'reason_code'      => $this->reasonCode,
				'attention_score'  => $this->attentionScore,
			]
		);
	}
}
