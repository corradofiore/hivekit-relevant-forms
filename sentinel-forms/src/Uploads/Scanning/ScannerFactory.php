<?php
/**
 * Malware scanner factory.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Scanning;

/**
 * Builds the active scanner. Extensions may override via the
 * sentinel_forms_malware_scanner filter.
 */
final class ScannerFactory {

	/**
	 * Create the active scanner.
	 */
	public static function make(): MalwareScannerInterface {
		$scanner = apply_filters( 'sentinel_forms_malware_scanner', null );

		if ( $scanner instanceof MalwareScannerInterface ) {
			return $scanner;
		}

		return new ClamAvScanner();
	}
}
