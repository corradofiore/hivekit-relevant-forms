<?php
/**
 * Deep-scan gateway factory.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Scanning;

/**
 * Builds the configured deep-scan gateway. Extensions may override via
 * the sentinel_forms_scan_gateway filter.
 */
final class ScanGatewayFactory {

	/**
	 * Create the gateway, or null when deep scanning is not configured.
	 */
	public static function make(): ?DeepScanGatewayInterface {
		$gateway = apply_filters( 'sentinel_forms_scan_gateway', null );

		if ( $gateway instanceof DeepScanGatewayInterface ) {
			return $gateway;
		}

		if ( BackendScanConfig::isConfigured() ) {
			return new BackendScanGateway( BackendScanConfig::url(), BackendScanConfig::key() );
		}

		return null;
	}
}
