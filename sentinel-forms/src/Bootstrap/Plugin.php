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
use SentinelForms\Admin\Controllers\EntryCorrectionController;
use SentinelForms\Admin\Controllers\EntryStatusController;
use SentinelForms\Admin\Controllers\FormActionController;
use SentinelForms\Admin\Controllers\FormSaveController;
use SentinelForms\Admin\Controllers\ImportController;
use SentinelForms\Admin\Controllers\RedirectTargetSearchController;
use SentinelForms\Admin\Pages\SettingsPage;
use SentinelForms\Assets\FrontendAssets;
use SentinelForms\Compat\WpForms\CompatLayer;
use SentinelForms\Database\MigrationRunner;
use SentinelForms\Forms\FormRenderer;
use SentinelForms\Forms\FormUsage;
use SentinelForms\Http\CloudBillingController;
use SentinelForms\Http\SubmissionHandler;
use SentinelForms\Http\UploadController;
use SentinelForms\Http\CloudController;
use SentinelForms\Uploads\Cleanup\UploadCleanup;
use SentinelForms\Uploads\Scanning\ScanService;

/**
 * Boots the plugin: registers admin pages, assets, shortcode, submission
 * handlers, Cloud endpoints and scheduled tasks.
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
		FormUsage::register();
		SubmissionHandler::register();
		UploadController::register();
		CloudController::register();
		CloudBillingController::register();
		FormSaveController::register();
		FormActionController::register();
		EntryStatusController::register();
		EntryCorrectionController::register();
		ImportController::register();
		RedirectTargetSearchController::register();

		// WPForms hook compatibility (Phase 7.5): active only when WPForms is absent.
		CompatLayer::register();

		add_action( 'sentinel_forms_scan_upload', [ ScanService::class, 'handleScheduled' ], 10, 1 );
		add_action( 'sentinel_forms_poll_scan_results', [ ScanService::class, 'pollBackendScans' ] );
		UploadCleanup::register();

		add_filter( 'cron_schedules', [ self::class, 'registerCronSchedules' ] );

		do_action( 'sentinel_forms_loaded' );
	}

	/**
	 * Runs database migrations on activation.
	 */
	public static function activate(): void {
		MigrationRunner::run();

		if ( false === wp_next_scheduled( 'sentinel_forms_cleanup_uploads' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'sentinel_forms_cleanup_uploads' );
		}

		if ( false === wp_next_scheduled( 'sentinel_forms_poll_scan_results' ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'sentinel_fifteen_minutes', 'sentinel_forms_poll_scan_results' );
		}

		do_action( 'sentinel_forms_activated' );
	}

	/**
	 * Custom cron intervals.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Registered schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function registerCronSchedules( array $schedules ): array {
		$schedules['sentinel_fifteen_minutes'] = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'sentinel-forms' ),
		];

		return $schedules;
	}

	/**
	 * Placeholder deactivation hook. No destructive cleanup on deactivation.
	 */
	public static function deactivate(): void {
		do_action( 'sentinel_forms_deactivated' );
	}
}
