<?php
/**
 * Malware scanner factory.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Uploads\Scanning;

/**
 * Builds the active scanner. Core ships only the pure-PHP basic scanner;
 * extensions (Relevant Pro) may supply a deep scanner through the
 * `relevant_forms_malware_scanner` filter (T2).
 */
final class ScannerFactory {

	/**
	 * Create the active scanner.
	 */
	public static function make(): MalwareScannerInterface {
		/**
		 * Filter the malware scanner. Return an implementation of
		 * MalwareScannerInterface to run a local deep scanner.
		 *
		 * @param MalwareScannerInterface|null $scanner Scanner or null for the basic default.
		 */
		$scanner = apply_filters( 'relevant_forms_malware_scanner', null );

		if ( $scanner instanceof MalwareScannerInterface ) {
			return $scanner;
		}

		return new BasicPhpScanner();
	}
}
