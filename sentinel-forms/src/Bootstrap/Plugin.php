<?php
/**
 * Plugin lifecycle and service registration.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Bootstrap;

use SentinelForms\Admin\AdminMenu;
use SentinelForms\Admin\Assets\AdminAssets;
use SentinelForms\Admin\Controllers\EntryStatusController;
use SentinelForms\Admin\Controllers\FormActionController;
use SentinelForms\Admin\Controllers\FormSaveController;
use SentinelForms\Admin\Pages\SettingsPage;
use SentinelForms\Assets\FrontendAssets;
use SentinelForms\Database\MigrationRunner;
use SentinelForms\Forms\FormRenderer;
use SentinelForms\Http\SubmissionHandler;

/**
 * Boots the plugin: registers admin pages, assets, shortcode, submission
 * handlers and MCP availability notices.
 */
final class Plugin {

	/**
	 * Register plugin services on plugins_loaded.
	 */
	public static function boot(): void {
		AdminMenu::register();
		AdminAssets::register();
		FrontendAssets::register();
		SettingsPage::register();
		FormRenderer::register();
		SubmissionHandler::register();
		FormSaveController::register();
		FormActionController::register();
		EntryStatusController::register();

		add_action( 'admin_notices', [ self::class, 'maybeShowMcpNotice' ] );

		do_action( 'sentinel_forms_loaded' );
	}

	/**
	 * Runs database migrations on activation.
	 */
	public static function activate(): void {
		MigrationRunner::run();

		do_action( 'sentinel_forms_activated' );
	}

	/**
	 * Placeholder deactivation hook. No destructive cleanup on deactivation.
	 */
	public static function deactivate(): void {
		do_action( 'sentinel_forms_deactivated' );
	}

	/**
	 * Shows a hint when the optional MCP Adapter plugin is missing.
	 */
	public static function maybeShowMcpNotice(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Sentinel Forms: install and activate the MCP Adapter plugin to expose form abilities to AI agents.', 'sentinel-forms' )
		);
	}
}
