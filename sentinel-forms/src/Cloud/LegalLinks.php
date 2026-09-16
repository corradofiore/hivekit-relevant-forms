<?php
/**
 * Legal disclosure links for the optional Cloud service (v2 §13).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Cloud;

/**
 * Terms, privacy and subprocessor documents live on the service operator's
 * site, so the plugin does not guess URLs: define the constants below in
 * wp-config.php (or filter `sentinel_forms_legal_links`) and the Cloud
 * settings screen links the actual documents.
 */
final class LegalLinks {

	/**
	 * wp-config constant per disclosure key.
	 *
	 * @var array<string, string>
	 */
	private const CONSTANTS = [
		'terms'         => 'SENTINEL_CLOUD_TERMS_URL',
		'privacy'       => 'SENTINEL_CLOUD_PRIVACY_URL',
		'subprocessors' => 'SENTINEL_CLOUD_SUBPROCESSORS_URL',
	];

	/**
	 * Configured links as key => URL. Unconfigured keys are omitted.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		$links = [];

		foreach ( self::CONSTANTS as $key => $constant ) {
			$url = self::definedUrl( $constant );

			if ( '' !== $url ) {
				$links[ $key ] = $url;
			}
		}

		/**
		 * Filter the external-service disclosure links shown in settings.
		 *
		 * @param array<string, string> $links URL per disclosure key.
		 */
		$links = (array) apply_filters( 'sentinel_forms_legal_links', $links );

		$clean = [];

		foreach ( $links as $key => $url ) {
			$url = esc_url_raw( (string) $url );

			if ( '' !== $url ) {
				$clean[ (string) $key ] = $url;
			}
		}

		return $clean;
	}

	/**
	 * URL for one disclosure key, or '' when not configured.
	 */
	public static function get( string $key ): string {
		$links = self::all();

		return $links[ $key ] ?? '';
	}

	/**
	 * Value of a string constant, or '' when undefined/empty.
	 */
	private static function definedUrl( string $constant ): string {
		if ( ! defined( $constant ) ) {
			return '';
		}

		return (string) constant( $constant );
	}
}
