<?php
/**
 * Front-end asset registration.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Assets;

use SentinelForms\Support\AssetVersion;

/**
 * Registers the public form stylesheet. Enqueued lazily by the renderer so
 * ordinary pages are not slowed down (doc §70).
 */
final class FrontendAssets {

	/**
	 * Register asset handles.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'registerAssets' ] );
	}

	/**
	 * Register (not enqueue) front-end assets.
	 */
	public static function registerAssets(): void {
		wp_register_style(
			'sentinel-forms',
			SENTINEL_FORMS_URL . 'assets/css/frontend.css',
			[],
			AssetVersion::get( 'assets/css/frontend.css' )
		);

		wp_register_script(
			'sentinel-forms-upload',
			SENTINEL_FORMS_URL . 'assets/js/frontend-upload.js',
			[],
			AssetVersion::get( 'assets/js/frontend-upload.js' ),
			true
		);

		wp_register_script(
			'sentinel-forms-counter',
			SENTINEL_FORMS_URL . 'assets/js/frontend-counter.js',
			[],
			AssetVersion::get( 'assets/js/frontend-counter.js' ),
			true
		);

		wp_register_script(
			'sentinel-forms-conditional',
			SENTINEL_FORMS_URL . 'assets/js/frontend-conditional.js',
			[],
			AssetVersion::get( 'assets/js/frontend-conditional.js' ),
			true
		);
	}
}
