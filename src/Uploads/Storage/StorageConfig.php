<?php
/**
 * Storage configuration (constants and settings).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Uploads\Storage;

use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Cloud\CloudConnection;

/**
 * Resolves storage settings from wp-config constants first, then plugin
 * settings. Core ships local private storage and Relevant Managed Secure
 * Storage; bring-your-own object storage is a Pro extension registered
 * through the `relevant_forms_storage_provider` filter (v2 §3/§5, T1).
 */
final class StorageConfig {

	/**
	 * Settings option key.
	 */
	private const OPTION = 'relevant_forms_settings';

	/**
	 * Active provider: local|cloud.
	 */
	public static function provider(): string {
		$allowed  = [ 'local', 'cloud' ];
		$constant = defined( 'RELEVANT_STORAGE_PROVIDER' ) ? (string) RELEVANT_STORAGE_PROVIDER : '';

		if ( '' !== $constant ) {
			return in_array( $constant, $allowed, true ) ? $constant : 'local';
		}

		$settings = (array) get_option( self::OPTION, [] );
		$selected = (string) ( $settings['storage_provider'] ?? '' );

		return in_array( $selected, $allowed, true ) ? $selected : 'local';
	}

	/**
	 * Whether unscanned (quarantined) files may be downloaded by admins.
	 * True unless the administrator opted into blocking unscanned
	 * downloads (doc §32/§44).
	 */
	public static function allowUnscannedDownload(): bool {
		$settings = (array) get_option( self::OPTION, [] );

		return empty( $settings['storage_block_unscanned_downloads'] );
	}

	/**
	 * Whether managed storage is selected and usable: Cloud configured,
	 * `managed_uploads` capability explicitly enabled, provider selected
	 * (v2 §7–8 consent rules).
	 */
	public static function isCloudUsable(): bool {
		if ( self::provider() !== 'cloud' ) {
			return false;
		}

		if ( ! CloudConfig::isConfigured() ) {
			return false;
		}

		return ( new CloudConnection() )->capabilityEnabled( CloudConnection::CAP_UPLOADS );
	}
}
