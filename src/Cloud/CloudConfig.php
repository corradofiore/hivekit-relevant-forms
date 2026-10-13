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
	 * Production Cloud service origin. The service URL is built in, so
	 * this is what a distributed build talks to unless a site overrides
	 * it in wp-config.php.
	 */
	public const DEFAULT_URL = 'https://app.relevantforms.com';

	/**
	 * Channel a build was cut for. A release build defines
	 * RELEVANT_FORMS_CHANNEL ('production' when absent); the QA build
	 * defines it as 'qa' together with RELEVANT_FORMS_CHANNEL_URL.
	 */
	public const CHANNEL_PRODUCTION = 'production';

	/**
	 * Alpha/beta channel, cut for internal and tester use.
	 */
	public const CHANNEL_QA = 'qa';

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
	 * pre-1.0 installs, the channel origin a build pinned
	 * (RELEVANT_FORMS_CHANNEL_URL, set by the QA build), then the
	 * production default.
	 */
	public static function url(): string {
		$settings = (array) get_option( self::OPTION, [] );

		return self::resolveOrigin(
			defined( 'RELEVANT_CLOUD_URL' ) ? (string) RELEVANT_CLOUD_URL : '',
			/**
			 * Filters the Relevant Cloud service origin.
			 *
			 * @param string $url Override URL; empty string when not overridden.
			 */
			(string) apply_filters( 'relevant_forms_cloud_url', '' ),
			(string) ( $settings['cloud_url'] ?? '' ),
			self::channelUrl(),
			self::DEFAULT_URL
		);
	}

	/**
	 * First non-empty candidate wins, in precedence order, with a trailing
	 * slash trimmed so callers can concatenate API_PATH safely.
	 *
	 * @param string $constant Site override (RELEVANT_CLOUD_URL).
	 * @param string $filtered Filter override.
	 * @param string $legacy   URL stored by a pre-1.0 install.
	 * @param string $channel  Origin the build pinned (QA builds only).
	 * @param string $fallback Production default.
	 */
	public static function resolveOrigin(
		string $constant,
		string $filtered,
		string $legacy,
		string $channel,
		string $fallback
	): string {
		foreach ( [ $constant, $filtered, $legacy, $channel ] as $candidate ) {
			$candidate = untrailingslashit( trim( $candidate ) );

			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return untrailingslashit( $fallback );
	}

	/**
	 * The channel this build was cut for ('production' unless a release
	 * build said otherwise). Diagnostics shows it so testers can tell at a
	 * glance which service an install talks to.
	 */
	public static function channel(): string {
		$channel = defined( 'RELEVANT_FORMS_CHANNEL' ) ? (string) RELEVANT_FORMS_CHANNEL : '';

		return '' !== $channel ? $channel : self::CHANNEL_PRODUCTION;
	}

	/**
	 * Whether this is a non-production build.
	 */
	public static function isPreReleaseChannel(): bool {
		return self::CHANNEL_PRODUCTION !== self::channel();
	}

	/**
	 * Service origin the build pinned, if any. Only the QA build defines
	 * it, so a production artifact carries no other origin at all.
	 */
	private static function channelUrl(): string {
		return defined( 'RELEVANT_FORMS_CHANNEL_URL' ) ? (string) RELEVANT_FORMS_CHANNEL_URL : '';
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
