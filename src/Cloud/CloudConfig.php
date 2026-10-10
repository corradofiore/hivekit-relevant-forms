<?php
/**
 * Relevant Cloud configuration (Phase 3 platform skeleton).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Cloud;

/**
 * Resolves the Cloud service URL and site key. The service URL is
 * code-owned (`DEFAULT_URL`) with staging overrides; the settings screen
 * only asks for the API key, which is stored write-only and only ever
 * shown back as a masked preview (v2 §8 consent rules).
 */
final class CloudConfig {

	/**
	 * Settings option key.
	 */
	private const OPTION = 'relevant_forms_settings';

	/**
	 * Production Cloud service origin.
	 */
	public const DEFAULT_URL = 'https://app.relevantforms.com';

	/**
	 * API version path. The single source of the service URL structure:
	 * every client call is built from origin + this path, so a future API
	 * version only changes this constant.
	 */
	public const API_PATH = '/api/v1';

	/**
	 * Cloud service origin. Precedence: the RELEVANT_CLOUD_URL constant
	 * (staging/dev override in wp-config.php), the
	 * `relevant_forms_cloud_url` filter, a stored legacy URL from
	 * pre-1.0 installs, then the production default.
	 */
	public static function url(): string {
		$constant = defined( 'RELEVANT_CLOUD_URL' ) ? (string) RELEVANT_CLOUD_URL : '';

		if ( '' !== $constant ) {
			return untrailingslashit( $constant );
		}

		/**
		 * Filters the Relevant Cloud service origin.
		 *
		 * @param string $url Override URL; empty string when not overridden.
		 */
		$filtered = (string) apply_filters( 'relevant_forms_cloud_url', '' );

		if ( '' !== $filtered ) {
			return untrailingslashit( $filtered );
		}

		$settings = (array) get_option( self::OPTION, [] );
		$legacy   = untrailingslashit( (string) ( $settings['cloud_url'] ?? '' ) );

		return '' !== $legacy ? $legacy : self::DEFAULT_URL;
	}

	/**
	 * Versioned API base: origin + API_PATH.
	 */
	public static function apiBase(): string {
		return self::url() . self::API_PATH;
	}

	/**
	 * Site-scoped bearer API key.
	 */
	public static function key(): string {
		$constant = defined( 'RELEVANT_CLOUD_API_KEY' ) ? (string) RELEVANT_CLOUD_API_KEY : '';

		if ( '' !== $constant ) {
			return $constant;
		}

		$settings = (array) get_option( self::OPTION, [] );

		return (string) ( $settings['cloud_api_key'] ?? '' );
	}

	/**
	 * Masked preview of a stored key: only the first and last four
	 * characters of realistically long keys are shown; shorter values are
	 * hidden entirely. The full key is never returned.
	 */
	public static function mask( string $key ): string {
		$hidden = str_repeat( '•', 8 );

		if ( strlen( $key ) < 16 ) {
			return $hidden;
		}

		return substr( $key, 0, 4 ) . $hidden . substr( $key, -4 );
	}

	/**
	 * Whether the Cloud API is configured. The service URL is built in;
	 * only the site key is required. No external call may happen before
	 * this returns true (v2 §7, §8).
	 */
	public static function isConfigured(): bool {
		return '' !== self::key();
	}
}
