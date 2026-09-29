<?php
/**
 * AI privacy redaction (doc §18). Controlled per form; default ON.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Ai;

/**
 * Replaces identifying data before anything leaves WordPress.
 */
final class Redactor {

	/**
	 * Whether redaction is enabled for this use.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Constructor.
	 *
	 * @param bool $enabled Whether to redact values (per-form privacy mode).
	 */
	public function __construct( bool $enabled = true ) {
		$this->enabled = $enabled;
	}

	/**
	 * Whether privacy mode is enabled.
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}

	/**
	 * Redact a single value.
	 */
	public function redact( string $value ): string {
		if ( ! $this->isEnabled() ) {
			return $value;
		}

		$patterns = [
			'/\b[^\s@]+@[^\s@]+\.[^\s@]+\b/i'      => '[EMAIL]',
			// IPs first: dotted quads would otherwise match the phone pattern.
			'/\b(?:\d{1,3}\.){3}\d{1,3}\b/'        => '[IP]',
			'/(?<!\d)\+?\d[\d\s().-]{5,}\d(?!\d)/' => '[PHONE]',
			'#https?://([^\s/]+)\S*#i'             => '[URL domain="$1"]',
		];

		/**
		 * Filter the redaction patterns (pattern => replacement).
		 *
		 * @param array<string, string> $patterns Redaction map.
		 */
		$patterns = (array) apply_filters( 'relevant_forms_ai_redaction', $patterns );

		$redacted = preg_replace( array_keys( $patterns ), array_values( $patterns ), $value );

		return is_string( $redacted ) ? $redacted : $value;
	}

	/**
	 * Redact all string values of a submission map.
	 *
	 * @param array<string, mixed> $values Field values.
	 * @return array<string, string>
	 */
	public function redactValues( array $values ): array {
		$map = [];

		foreach ( $values as $fieldId => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}

			if ( is_scalar( $value ) ) {
				$map[ (string) $fieldId ] = $this->redact( (string) $value );
			}
		}

		return $map;
	}
}
