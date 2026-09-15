<?php
/**
 * Storage configuration (constants and settings).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Storage;

/**
 * Resolves storage settings from wp-config constants first, then plugin
 * settings. Never exposes secrets back to the UI (doc §55).
 */
final class StorageConfig {

	/**
	 * Settings option key.
	 */
	private const OPTION = 'sentinel_forms_settings';

	/**
	 * Active provider: local|s3.
	 */
	public static function provider(): string {
		$constant = defined( 'SENTINEL_STORAGE_PROVIDER' ) ? (string) SENTINEL_STORAGE_PROVIDER : '';

		if ( '' !== $constant ) {
			return in_array( $constant, [ 'local', 's3' ], true ) ? $constant : 'local';
		}

		$settings = (array) get_option( self::OPTION, [] );

		return 's3' === (string) ( $settings['storage_provider'] ?? '' ) ? 's3' : 'local';
	}

	/**
	 * S3-compatible endpoint (required for s3).
	 */
	public static function endpoint(): string {
		return self::value( 'SENTINEL_STORAGE_ENDPOINT', 'storage_endpoint' );
	}

	/**
	 * Region for SigV4 signing (required for s3).
	 */
	public static function region(): string {
		return self::value( 'SENTINEL_STORAGE_REGION', 'storage_region' );
	}

	/**
	 * Bucket name (required for s3).
	 */
	public static function bucket(): string {
		return self::value( 'SENTINEL_STORAGE_BUCKET', 'storage_bucket' );
	}

	/**
	 * Access key id (required for s3).
	 */
	public static function accessKey(): string {
		return self::value( 'SENTINEL_STORAGE_ACCESS_KEY', 'storage_access_key' );
	}

	/**
	 * Secret access key (required for s3). Never displayed in the UI.
	 */
	public static function secretKey(): string {
		$constant = defined( 'SENTINEL_STORAGE_SECRET_KEY' ) ? (string) SENTINEL_STORAGE_SECRET_KEY : '';

		if ( '' !== $constant ) {
			return $constant;
		}

		$settings = (array) get_option( self::OPTION, [] );

		return (string) ( $settings['storage_secret_key'] ?? '' );
	}

	/**
	 * Whether unscanned (quarantined) files may be downloaded by admins in
	 * this Phase-2 build. Defaults to true until Phase 3 provides scanning.
	 */
	public static function allowUnscannedDownload(): bool {
		$settings = (array) get_option( self::OPTION, [] );

		return empty( $settings['storage_block_unscanned_downloads'] );
	}

	/**
	 * Whether the s3 provider is fully configured.
	 */
	public static function isS3Configured(): bool {
		return self::provider() === 's3'
			&& '' !== self::endpoint()
			&& '' !== self::region()
			&& '' !== self::bucket()
			&& '' !== self::accessKey()
			&& '' !== self::secretKey();
	}

	/**
	 * Constant-first, settings-second value lookup.
	 */
	private static function value( string $constantName, string $settingKey ): string {
		$constant = defined( $constantName ) ? (string) constant( $constantName ) : '';

		if ( '' !== $constant ) {
			return $constant;
		}

		$settings = (array) get_option( self::OPTION, [] );

		return (string) ( $settings[ $settingKey ] ?? '' );
	}
}
