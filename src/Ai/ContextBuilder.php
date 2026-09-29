<?php
/**
 * Classification context builder (doc §14, v2 §4).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Ai;

use RelevantForms\AntiSpam\SpamSignals;
use RelevantForms\Forms\Form;
use RelevantForms\Support\TextList;

/**
 * Assembles the payload sent to the Cloud classification endpoint.
 * The submission is redacted before it ever leaves WordPress unless
 * privacy mode is switched off for the form (doc §18).
 */
final class ContextBuilder {

	/**
	 * Maximum fields/entries forwarded.
	 */
	private const MAX_FIELDS = 30;

	/**
	 * Maximum characters per forwarded value.
	 */
	private const MAX_VALUE_LENGTH = 2000;

	/**
	 * Redactor.
	 *
	 * @var Redactor
	 */
	private Redactor $redactor;

	/**
	 * Preference profile.
	 *
	 * @var PreferenceProfile
	 */
	private PreferenceProfile $profile;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->redactor = new Redactor();
		$this->profile  = new PreferenceProfile();
	}

	/**
	 * Build the classification context.
	 *
	 * @param Form                 $form       Form entity.
	 * @param array<string, mixed> $values     Sanitized submission values.
	 * @param array<string, mixed> $protection Local anti-spam evaluation.
	 * @return array<string, mixed>
	 */
	public function build( Form $form, array $values, array $protection ): array {
		$policy = $form->schema->aiPolicy();

		// Privacy is a per-form setting (default ON); it governs everything
		// sent for this classification, including the corrections profile.
		$this->redactor = new Redactor( (bool) ( $policy['privacy_mode'] ?? true ) );

		$context = [
			'form_name'          => mb_substr( $form->name, 0, 200 ),
			'form_purpose'       => mb_substr( trim( (string) ( $policy['purpose'] ?? '' ) ), 0, 500 ),
			'form_spam'          => $this->lines( (string) ( $policy['spam'] ?? '' ) ),
			'field_labels'       => $this->fieldLabels( $form ),
			'priorities'         => $this->lines( (string) ( $policy['priorities'] ?? '' ) ),
			'preference_profile' => $this->profile->guidance(),
			'recent_corrections' => $this->recentCorrections(),
			'submission'         => $this->submission( $values ),
			'local_signals'      => $this->localSignals( $protection ),
		];

		/**
		 * Filter the classification context before transmission.
		 *
		 * @param array<string, mixed> $context Context payload.
		 * @param Form                 $form    Form entity.
		 */
		return (array) apply_filters( 'relevant_forms_ai_context', $context, $form );
	}

	/**
	 * Recent admin corrections (doc §26–27), redacted for transmission.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function recentCorrections(): array {
		$corrections = [];

		foreach ( $this->profile->recent( 10 ) as $correction ) {
			$corrections[] = [
				'verdict'     => (string) ( $correction['verdict'] ?? '' ),
				'reason_code' => (string) ( $correction['reason_code'] ?? 'unknown' ),
				'summary'     => $this->redactor->redact( (string) ( $correction['summary'] ?? '' ) ),
			];
		}

		return $corrections;
	}

	/**
	 * Field id → label map.
	 *
	 * @return array<string, string>
	 */
	private function fieldLabels( Form $form ): array {
		$labels = [];

		foreach ( $form->schema->fields() as $field ) {
			if ( count( $labels ) >= self::MAX_FIELDS ) {
				break;
			}

			$id = (string) ( $field['id'] ?? '' );

			if ( '' === $id ) {
				continue;
			}

			$labels[ $id ] = mb_substr( trim( wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ) ), 0, 120 );
		}

		return $labels;
	}

	/**
	 * Redacted submission values.
	 *
	 * @param array<string, mixed> $values Raw values.
	 * @return array<string, string>
	 */
	private function submission( array $values ): array {
		$redacted = $this->redactor->redactValues( $values );
		$subset   = [];

		foreach ( $redacted as $fieldId => $value ) {
			if ( count( $subset ) >= self::MAX_FIELDS ) {
				break;
			}

			$subset[ $fieldId ] = mb_substr( $value, 0, self::MAX_VALUE_LENGTH );
		}

		return $subset;
	}

	/**
	 * Deterministic local signals forwarded for context.
	 *
	 * @param array<string, mixed> $protection Local evaluation result.
	 * @return array<string, mixed>
	 */
	private function localSignals( array $protection ): array {
		$signals = $protection['signals'] ?? null;
		$data    = $signals instanceof SpamSignals
			? $signals->toArray()
			: ( is_array( $signals ) ? $signals : [] );

		return [
			'spam_score'         => (float) ( $data['spam_score'] ?? 0.0 ),
			'links_detected'     => (int) ( $data['links_detected'] ?? 0 ),
			'duplicate_count'    => (int) ( $data['duplicate_count'] ?? 0 ),
			'submission_time_ms' => (int) ( $data['submission_time_ms'] ?? 0 ),
			'recent_submissions' => (int) ( $data['recent_submissions'] ?? 0 ),
			'rate_limit'         => (int) ( $data['rate_limit'] ?? 0 ),
			'submission_hash'    => (string) ( $protection['hash'] ?? '' ),
		];
	}

	/**
	 * Multiline textarea → trimmed non-empty lines.
	 *
	 * @return array<int, string>
	 */
	private function lines( string $text ): array {
		$lines = [];

		foreach ( TextList::lines( $text ) as $line ) {
			$lines[] = mb_substr( $line, 0, 200 );

			if ( count( $lines ) >= 20 ) {
				break;
			}
		}

		return $lines;
	}
}
