<?php
/**
 * Admin asset registration.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Assets;

/**
 * Registers admin styles and scripts; pages enqueue them lazily.
 */
final class AdminAssets {

	/**
	 * Register asset handles.
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	/**
	 * Enqueue on Sentinel Forms admin pages only.
	 *
	 * @param string $hookSuffix Current admin page hook.
	 */
	public static function enqueue( string $hookSuffix ): void {
		if ( false === strpos( $hookSuffix, 'sentinel-' ) ) {
			return;
		}

		wp_enqueue_style(
			'sentinel-forms-admin',
			SENTINEL_FORMS_URL . 'assets/css/admin.css',
			[],
			SENTINEL_FORMS_VERSION
		);

		wp_enqueue_script(
			'sentinel-forms-admin',
			SENTINEL_FORMS_URL . 'assets/js/admin.js',
			[],
			SENTINEL_FORMS_VERSION,
			true
		);
	}
}
