<?php
/**
 * Backend deep-scan configuration (Stage 2).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Uploads\Scanning;

/**
 * Resolves the backend scanning API credentials: constants first,
 * then plugin settings. The API key is never displayed back.
 */
final class BackendScanConfig {

	/**
	 * Settings option key.
	 */
	private const OPTION = 'relevant_forms_settings';

	/**
	 * Backend API base URL (e.g. https://api.example.com).
	 */
	public static function url(): string {
		$constant = defined( 'RELEVANT_SCAN_BACKEND_URL' ) ? (string) RELEVANT_SCAN_BACKEND_URL : '';

		if ( '' !== $constant ) {
			return untrailingslashit( $constant );
		}

		$settings = (array) get_option( self::OPTION, [] );

		return untrailingslashit( (string) ( $settings['scan_backend_url'] ?? '' ) );
	}

	/**
	 * Bearer API key.
	 */
	public static function key(): string {
		$constant = defined( 'RELEVANT_SCAN_BACKEND_KEY' ) ? (string) RELEVANT_SCAN_BACKEND_KEY : '';

		if ( '' !== $constant ) {
			return $constant;
		}

		$settings = (array) get_option( self::OPTION, [] );

		return (string) ( $settings['scan_backend_key'] ?? '' );
	}

	/**
	 * Whether the backend deep-scan endpoint is configured.
	 */
	public static function isConfigured(): bool {
		return '' !== self::url() && '' !== self::key();
	}
}
