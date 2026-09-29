<?php
/**
 * Shared textarea → list splitting.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Support;

/**
 * Splits multiline textarea input into trimmed, non-empty lines. Callers
 * keep their own extra policy (de-duplication, caps, validation).
 */
final class TextList {

	/**
	 * Split on any newline style; drop lines empty after trim.
	 *
	 * @param string $text Raw textarea value.
	 * @return array<int, string>
	 */
	public static function lines( string $text ): array {
		$parts = preg_split( '/\r\n|\r|\n/', $text );

		$lines = [];

		foreach ( is_array( $parts ) ? $parts : [] as $line ) {
			$line = trim( $line );

			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}
}
