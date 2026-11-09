<?php
/**
 * Per-site preference profile from admin corrections (doc §26–27).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Ai;

/**
 * Aggregates administrator corrections into a lightweight per-site
 * profile. Feedback improves context for future classifications; it is
 * never used for model training (doc §26).
 */
final class PreferenceProfile {

	/**
	 * Option key.
	 */
	public const OPTION = 'relevant_forms_preference_profile';

	/**
	 * Allowed correction verdicts (doc §26).
	 *
	 * @var array<int, string>
	 */
	public const VERDICTS = [ 'legitimate', 'spam', 'priority', 'not_important' ];

	/**
	 * Maximum stored corrections (most recent kept).
	 */
	private const MAX_CORRECTIONS = 50;

	/**
	 * Minimum corrections before a reason code becomes guidance.
	 */
	private const MIN_EVIDENCE = 2;

	/**
	 * Maximum stored summary length.
	 */
	private const MAX_SUMMARY = 280;

	/**
	 * Record a correction and refresh the tallies.
	 *
	 * @param string               $verdict        One of VERDICTS.
	 * @param array<string, mixed> $classification Original classification payload.
	 */
	public function record( string $verdict, array $classification ): void {
		if ( ! in_array( $verdict, self::VERDICTS, true ) ) {
			return;
		}

		$profile = $this->all();

		$reasonCode = (string) ( $classification['reason_code'] ?? 'unknown' );

		if ( ! in_array( $reasonCode, Classification::REASON_CODES, true ) ) {
			$reasonCode = 'unknown';
		}

		$profile['corrections'][] = [
			'verdict'         => $verdict,
			'reason_code'     => $reasonCode,
			'attention_score' => (int) ( $classification['attention_score'] ?? 0 ),
			'summary'         => mb_substr( trim( (string) ( $classification['summary'] ?? '' ) ), 0, self::MAX_SUMMARY ),
			'at'              => current_time( 'mysql', true ),
		];

		if ( count( $profile['corrections'] ) > self::MAX_CORRECTIONS ) {
			$profile['corrections'] = array_slice( $profile['corrections'], -self::MAX_CORRECTIONS );
		}

		$profile['tallies'][ $reasonCode ][ $verdict ] = ( (int) ( $profile['tallies'][ $reasonCode ][ $verdict ] ?? 0 ) ) + 1;

		update_option( self::OPTION, $profile );
	}

	/**
	 * Derived guidance for the classification context (doc §27).
	 *
	 * @return array{usually_spam: array<int, string>, usually_legitimate: array<int, string>, min_corrections: int}
	 */
	public function guidance(): array {
		$tallies = $this->all()['tallies'];

		$usuallySpam       = [];
		$usuallyLegitimate = [];

		foreach ( $tallies as $reasonCode => $counts ) {
			$spam       = (int) ( $counts['spam'] ?? 0 );
			$legitimate = (int) ( $counts['legitimate'] ?? 0 );

			if ( $spam >= self::MIN_EVIDENCE && $spam > $legitimate ) {
				$usuallySpam[] = (string) $reasonCode;
			}

			if ( $legitimate >= self::MIN_EVIDENCE && $legitimate > $spam ) {
				$usuallyLegitimate[] = (string) $reasonCode;
			}
		}

		$guidance = [
			'usually_spam'       => $usuallySpam,
			'usually_legitimate' => $usuallyLegitimate,
			'min_corrections'    => self::MIN_EVIDENCE,
		];

		/**
		 * Filter the derived per-site preference profile.
		 *
		 * @param array<string, mixed> $guidance Derived profile.
		 */
		return (array) apply_filters( 'relevant_forms_preference_profile', $guidance );
	}

	/**
	 * Most recent corrections, newest first.
	 *
	 * @param int $limit Maximum entries.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 10 ): array {
		$corrections = $this->all()['corrections'];

		return array_slice( array_reverse( $corrections ), 0, max( 1, $limit ) );
	}

	/**
	 * Stored profile, normalized.
	 *
	 * @return array{corrections: array<int, array<string, mixed>>, tallies: array<string, array<string, int>>}
	 */
	private function all(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		$corrections = is_array( $stored['corrections'] ?? null ) ? array_values( $stored['corrections'] ) : [];
		$tallies     = is_array( $stored['tallies'] ?? null ) ? $stored['tallies'] : [];

		return [
			'corrections' => $corrections,
			'tallies'     => $tallies,
		];
	}
}
