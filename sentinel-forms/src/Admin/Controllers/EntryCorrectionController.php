<?php
/**
 * Entry classification correction controller (doc §26).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Controllers;

use SentinelForms\Ai\PreferenceProfile;
use SentinelForms\Entries\Entry;
use SentinelForms\Entries\EntryRepository;
use SentinelForms\Events\EventRecorder;

/**
 * Stores administrator feedback on AI decisions ("Mark legitimate",
 * "Mark spam", "Mark priority", "Not important") and feeds the
 * per-site preference profile.
 */
final class EntryCorrectionController {

	/**
	 * Verdict → resulting entry status.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_BY_VERDICT = [
		'legitimate'    => Entry::STATUS_READ,
		'spam'          => Entry::STATUS_SPAM,
		'priority'      => Entry::STATUS_PRIORITY,
		'not_important' => Entry::STATUS_READ,
	];

	/**
	 * Register the admin-post action.
	 */
	public static function register(): void {
		add_action( 'admin_post_sentinel_forms_correct_classification', [ self::class, 'handle' ] );
	}

	/**
	 * Handle the correction request.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		$raw     = wp_unslash( $_POST );
		$entryId = isset( $raw['entry_id'] ) ? absint( $raw['entry_id'] ) : 0;
		$verdict = sanitize_key( (string) ( $raw['verdict'] ?? '' ) );

		if ( $entryId <= 0 || ! in_array( $verdict, PreferenceProfile::VERDICTS, true ) ) {
			wp_die( esc_html__( 'Invalid correction.', 'sentinel-forms' ), '', [ 'response' => 400 ] );
		}

		check_admin_referer( 'sentinel_entry_correction_' . $entryId );

		$repository = new EntryRepository();
		$entry      = $repository->find( $entryId );

		if ( null === $entry ) {
			wp_die( esc_html__( 'Entry not found.', 'sentinel-forms' ), '', [ 'response' => 404 ] );
		}

		$classification = is_array( $entry->meta['_classification'] ?? null ) ? $entry->meta['_classification'] : [];

		if ( [] === $classification ) {
			wp_die( esc_html__( 'This entry has no AI classification to correct.', 'sentinel-forms' ), '', [ 'response' => 400 ] );
		}

		$repository->updateMeta(
			$entryId,
			'_classification_correction',
			[
				'verdict'  => $verdict,
				'original' => $classification,
				'at'       => current_time( 'mysql', true ),
				'user_id'  => get_current_user_id(),
			]
		);

		$repository->updateStatus( $entryId, self::STATUS_BY_VERDICT[ $verdict ] );

		EventRecorder::record(
			$entryId,
			'classification_corrected',
			[
				'verdict'         => $verdict,
				'reason_code'     => (string) ( $classification['reason_code'] ?? 'unknown' ),
				'attention_score' => (int) ( $classification['attention_score'] ?? 0 ),
			]
		);

		( new PreferenceProfile() )->record( $verdict, $classification );

		$url = (string) apply_filters(
			'sentinel_forms_redirect',
			admin_url( 'admin.php?page=sentinel-entry&entry_id=' . $entryId . '&corrected=1' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
