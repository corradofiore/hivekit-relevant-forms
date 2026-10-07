<?php
/**
 * Plugin lifecycle and service registration.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Bootstrap;

use RelevantForms\Admin\AdminMenu;
use RelevantForms\Admin\Assets\AdminAssets;
use RelevantForms\Admin\Controllers\ApiStatusController;
use RelevantForms\Admin\Controllers\EntryCorrectionController;
use RelevantForms\Admin\Controllers\EntryStatusController;
use RelevantForms\Admin\Controllers\FormActionController;
use RelevantForms\Admin\Controllers\FormSaveController;
use RelevantForms\Admin\Controllers\ImportController;
use RelevantForms\Admin\Controllers\LogsController;
use RelevantForms\Admin\Controllers\PaymentEntryController;
use RelevantForms\Admin\Controllers\PaymentSettingsController;
use RelevantForms\Admin\Controllers\RedirectTargetSearchController;
use RelevantForms\Admin\Controllers\ResumeLinkController;
use RelevantForms\Admin\Controllers\SmsVerificationController;
use RelevantForms\Admin\Controllers\TestEmailController;
use RelevantForms\Admin\Pages\SettingsPage;
use RelevantForms\Assets\FrontendAssets;
use RelevantForms\Database\MigrationRunner;
use RelevantForms\Entries\Cleanup\PartialEntryCleanup;
use RelevantForms\Forms\FormRenderer;
use RelevantForms\Forms\FormUsage;
use RelevantForms\Http\CloudBillingController;
use RelevantForms\Http\PaymentReturnHandler;
use RelevantForms\Http\SaveResumeController;
use RelevantForms\Http\SubmissionHandler;
use RelevantForms\Http\UploadController;
use RelevantForms\Http\CloudController;
use RelevantForms\Integrations\BeaverBuilder\BeaverIntegration;
use RelevantForms\Integrations\Bricks\BricksIntegration;
use RelevantForms\Integrations\Divi\DiviIntegration;
use RelevantForms\Integrations\Elementor\ElementorIntegration;
use RelevantForms\Integrations\Gutenberg\GutenbergIntegration;
use RelevantForms\Integrations\WPBakery\WPBakeryIntegration;
use RelevantForms\Uploads\Cleanup\UploadCleanup;
use RelevantForms\Uploads\Scanning\ScanService;

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
		BeaverIntegration::register();
		BricksIntegration::register();
		DiviIntegration::register();
		ElementorIntegration::register();
		GutenbergIntegration::register();
		WPBakeryIntegration::register();
		SubmissionHandler::register();
		UploadController::register();
		SaveResumeController::register();
		CloudController::register();
		CloudBillingController::register();
		FormSaveController::register();
		FormActionController::register();
		LogsController::register();
		EntryStatusController::register();
		EntryCorrectionController::register();
		ImportController::register();
		RedirectTargetSearchController::register();
		SmsVerificationController::register();
		TestEmailController::register();
		ApiStatusController::register();
		ResumeLinkController::register();
		PaymentReturnHandler::register();
		PaymentEntryController::register();
		PaymentSettingsController::register();

		add_action( 'relevant_forms_scan_upload', [ ScanService::class, 'handleScheduled' ], 10, 1 );
		add_action( 'relevant_forms_poll_scan_results', [ ScanService::class, 'pollBackendScans' ] );
		UploadCleanup::register();
		PartialEntryCleanup::register();

		add_filter( 'cron_schedules', [ self::class, 'registerCronSchedules' ] );

		do_action( 'relevant_forms_loaded' );
	}

	/**
	 * Runs database migrations on activation.
	 */
	public static function activate(): void {
		MigrationRunner::run();

		if ( false === wp_next_scheduled( 'relevant_forms_cleanup_uploads' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'relevant_forms_cleanup_uploads' );
		}

		if ( false === wp_next_scheduled( 'relevant_forms_poll_scan_results' ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'relevant_fifteen_minutes', 'relevant_forms_poll_scan_results' );
		}

		if ( false === wp_next_scheduled( 'relevant_forms_cleanup_partials' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'relevant_forms_cleanup_partials' );
		}

		do_action( 'relevant_forms_activated' );
	}

	/**
	 * Custom cron intervals.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Registered schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function registerCronSchedules( array $schedules ): array {
		$schedules['relevant_fifteen_minutes'] = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'relevant-forms' ),
		];

		return $schedules;
	}

	/**
	 * Placeholder deactivation hook. No destructive cleanup on deactivation.
	 */
	public static function deactivate(): void {
		do_action( 'relevant_forms_deactivated' );
	}
}
