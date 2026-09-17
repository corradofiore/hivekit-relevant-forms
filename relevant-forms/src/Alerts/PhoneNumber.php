<?php
/**
 * Phone number normalization (form editor SMS recipient).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Alerts;

/**
 * Normalizes phone numbers to E.164 so the form editor stores and
 * compares one canonical form. Mirrors the backend validator; the Cloud
 * API remains the authority – this only keeps local keys stable.
 */
final class PhoneNumber {

	/**
	 * Normalize an input number to E.164, or null when invalid.
	 *
	 * Accepts optional separators ("+39 333 123 4567", "+1 (555) 000-1111",
	 * "0049 151 1234567"). Numbers without an explicit country code are
	 * rejected so identifiers stay unambiguous.
	 *
	 * @param string $input Raw input.
	 */
	public static function normalize( string $input ): ?string {
		$stripped = (string) preg_replace( '/[\s\-().\/]+/', '', trim( $input ) );

		if ( '' === $stripped ) {
			return null;
		}

		if ( str_starts_with( $stripped, '00' ) ) {
			$stripped = '+' . substr( $stripped, 2 );
		}

		if ( ! str_starts_with( $stripped, '+' ) ) {
			return null;
		}

		if ( 1 !== preg_match( '/^\+[1-9][0-9]{6,14}$/', $stripped ) ) {
			return null;
		}

		return $stripped;
	}
}
