<?php
/**
 * Cache-busting asset versions.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Support;

/**
 * Builds version strings for enqueued assets.
 *
 * The release version is suffixed with the file modification time so browsers
 * re-fetch an edited asset during development without a manual version bump.
 * In a release build the timestamp only changes when the shipped file does.
 */
final class AssetVersion {

	/**
	 * Version string for one asset, e.g. "1.0.0.1758000000".
	 *
	 * @param string $relativePath Path relative to the plugin root.
	 */
	public static function get( string $relativePath ): string {
		$path = RELEVANT_FORMS_PATH . $relativePath;

		if ( ! file_exists( $path ) ) {
			return RELEVANT_FORMS_VERSION;
		}

		return RELEVANT_FORMS_VERSION . '.' . (string) (int) filemtime( $path );
	}
}
