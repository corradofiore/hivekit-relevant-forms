<?php
/**
 * Entry status resolution from the local score and the cloud verdict.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Entries;

use RelevantForms\Ai\AttentionScore;
use RelevantForms\Ai\Classification;

/**
 * One rule for turning local anti-spam signals plus a classification into
 * an entry status. Shared so a verdict that arrives late (a retried
 * classification) settles the entry exactly like one that arrived with the
 * submission.
 */
final class StatusResolver {

	/**
	 * Resolve the status for a submission.
	 *
	 * @param string              $localStatus Status from local anti-spam.
	 * @param Classification|null $classification Verdict, when available.
	 */
	public static function resolve( string $localStatus, ?Classification $classification ): string {
		if ( Entry::STATUS_SPAM === $localStatus || null === $classification ) {
			return $localStatus;
		}

		if ( $classification->spamProbability >= 0.95 ) {
			return Entry::STATUS_SPAM;
		}

		if ( $classification->spamProbability >= 0.70 ) {
			return Entry::STATUS_SUSPICIOUS;
		}

		$status = AttentionScore::isPriority( $classification->attentionScore )
			? Entry::STATUS_PRIORITY
			: $localStatus;

		/**
		 * Filter the resolved entry status.
		 *
		 * @param string              $status         Resolved status.
		 * @param Classification      $classification Verdict.
		 * @param string              $localStatus    Status from local anti-spam.
		 */
		return (string) apply_filters( 'relevant_forms_entry_status', $status, $classification, $localStatus );
	}

	/**
	 * Whether a verdict may still settle an entry: only while nobody has
	 * acted on it (an operator who read or archived it outranks a late
	 * verdict, and payment states are not ours to change).
	 *
	 * @param string $status Current entry status.
	 */
	public static function isSettledByVerdict( string $status ): bool {
		return in_array( $status, [ Entry::STATUS_NEW, Entry::STATUS_SUSPICIOUS ], true );
	}
}
