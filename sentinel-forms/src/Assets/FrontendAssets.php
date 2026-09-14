<?php
/**
 * Front-end asset registration.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Assets;

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
			SENTINEL_FORMS_VERSION
		);
	}
}
