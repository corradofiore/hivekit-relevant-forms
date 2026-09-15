<?php
/**
 * Sentinel Cloud configuration (Phase 3 platform skeleton).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Cloud;

/**
 * Resolves the Cloud API credentials: constants first, then plugin
 * settings. The API key is never displayed back (v2 §8 consent rules).
 */
final class CloudConfig {

	/**
	 * Settings option key.
	 */
	private const OPTION = 'sentinel_forms_settings';

	/**
	 * Cloud API base URL (e.g. https://cloud.example.com).
	 */
	public static function url(): string {
		$constant = defined( 'SENTINEL_CLOUD_URL' ) ? (string) SENTINEL_CLOUD_URL : '';

		if ( '' !== $constant ) {
			return untrailingslashit( $constant );
		}

		$settings = (array) get_option( self::OPTION, [] );

		return untrailingslashit( (string) ( $settings['cloud_url'] ?? '' ) );
	}

	/**
	 * Site-scoped bearer API key.
	 */
	public static function key(): string {
		$constant = defined( 'SENTINEL_CLOUD_API_KEY' ) ? (string) SENTINEL_CLOUD_API_KEY : '';

		if ( '' !== $constant ) {
			return $constant;
		}

		$settings = (array) get_option( self::OPTION, [] );

		return (string) ( $settings['cloud_api_key'] ?? '' );
	}

	/**
	 * Whether the Cloud API is configured. No external call may happen
	 * before this returns true (v2 §7, §8).
	 */
	public static function isConfigured(): bool {
		return '' !== self::url() && '' !== self::key();
	}
}
