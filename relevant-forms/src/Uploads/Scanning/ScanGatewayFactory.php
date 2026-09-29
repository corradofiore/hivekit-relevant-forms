<?php
/**
 * Deep-scan gateway factory.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Uploads\Scanning;

use RelevantForms\Cloud\CloudConfig;

/**
 * Builds the configured deep-scan gateway. Extensions may override via
 * the relevant_forms_scan_gateway filter.
 */
final class ScanGatewayFactory {

	/**
	 * Create the gateway, or null when deep scanning is not configured.
	 *
	 * Deep scanning is provided by Relevant Cloud: the gateway uses the
	 * built-in service URL and the Cloud site key — no separate scan
	 * endpoint or key exists.
	 */
	public static function make(): ?DeepScanGatewayInterface {
		$gateway = apply_filters( 'relevant_forms_scan_gateway', null );

		if ( $gateway instanceof DeepScanGatewayInterface ) {
			return $gateway;
		}

		if ( CloudConfig::isConfigured() ) {
			return new BackendScanGateway( CloudConfig::apiBase(), CloudConfig::key() );
		}

		return null;
	}
}
