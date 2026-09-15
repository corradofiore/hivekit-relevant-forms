<?php
/**
 * Classification context builder (doc §14, v2 §4).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Ai;

use SentinelForms\AntiSpam\SpamSignals;
use SentinelForms\Forms\Form;

/**
 * Assembles the payload sent to the Cloud classification endpoint.
 * The submission is redacted before it ever leaves WordPress.
 */
final class ContextBuilder {

	/**
	 * Settings option key.
	 */
	private const OPTION = 'sentinel_forms_settings';

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
	 * Constructor.
	 */
	public function __construct() {
		$this->redactor = new Redactor();
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
		$settings = (array) get_option( self::OPTION, [] );
		$policy   = $form->schema->aiPolicy();

		$context = [
			'site_purpose'  => mb_substr( trim( (string) ( $settings['ai_site_purpose'] ?? '' ) ), 0, 500 ),
			'form_name'     => mb_substr( $form->name, 0, 200 ),
			'form_purpose'  => mb_substr( trim( (string) ( $policy['purpose'] ?? '' ) ), 0, 500 ),
			'field_labels'  => $this->fieldLabels( $form ),
			'priorities'    => $this->lines( (string) ( $settings['ai_priorities'] ?? '' ) ),
			'unwanted'      => $this->lines( (string) ( $settings['ai_unwanted'] ?? '' ) ),
			'submission'    => $this->submission( $values ),
			'local_signals' => $this->localSignals( $protection ),
		];

		/**
		 * Filter the classification context before transmission.
		 *
		 * @param array<string, mixed> $context Context payload.
		 * @param Form                 $form    Form entity.
		 */
		return (array) apply_filters( 'sentinel_forms_ai_context', $context, $form );
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
		$parts = preg_split( '/\r\n|\r|\n/', $text );

		foreach ( is_array( $parts ) ? $parts : [] as $line ) {
			$line = mb_substr( trim( $line ), 0, 200 );

			if ( '' !== $line ) {
				$lines[] = $line;
			}

			if ( count( $lines ) >= 20 ) {
				break;
			}
		}

		return $lines;
	}
}
