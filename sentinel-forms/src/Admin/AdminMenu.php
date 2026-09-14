<?php
/**
 * Admin menu registration.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin;

use SentinelForms\Admin\Pages\DiagnosticsPage;
use SentinelForms\Admin\Pages\EntryListPage;
use SentinelForms\Admin\Pages\EntryViewPage;
use SentinelForms\Admin\Pages\FormEditPage;
use SentinelForms\Admin\Pages\FormListPage;
use SentinelForms\Admin\Pages\SettingsPage;

/**
 * Registers the Sentinel Forms menu and submenu pages.
 */
final class AdminMenu {

	/**
	 * Capability required to manage Sentinel Forms.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Register the admin_menu hook.
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'build' ] );
	}

	/**
	 * Build the menu.
	 */
	public static function build(): void {
		add_menu_page(
			__( 'Sentinel Forms', 'sentinel-forms' ),
			__( 'Sentinel Forms', 'sentinel-forms' ),
			self::CAPABILITY,
			'sentinel-forms',
			[ FormListPage::class, 'render' ],
			'dashicons-email-alt',
			30
		);

		add_submenu_page(
			'sentinel-forms',
			__( 'Forms', 'sentinel-forms' ),
			__( 'Forms', 'sentinel-forms' ),
			self::CAPABILITY,
			'sentinel-forms',
			[ FormListPage::class, 'render' ]
		);

		add_submenu_page(
			'sentinel-forms',
			__( 'Add New Form', 'sentinel-forms' ),
			__( 'Add New Form', 'sentinel-forms' ),
			self::CAPABILITY,
			'sentinel-form-new',
			[ FormEditPage::class, 'render' ]
		);

		add_submenu_page(
			'sentinel-forms',
			__( 'Entries', 'sentinel-forms' ),
			__( 'Entries', 'sentinel-forms' ),
			self::CAPABILITY,
			'sentinel-entries',
			[ EntryListPage::class, 'render' ]
		);

		// Hidden page: single entry view.
		add_submenu_page(
			'',
			__( 'Entry', 'sentinel-forms' ),
			__( 'Entry', 'sentinel-forms' ),
			self::CAPABILITY,
			'sentinel-entry',
			[ EntryViewPage::class, 'render' ]
		);

		add_submenu_page(
			'sentinel-forms',
			__( 'Settings', 'sentinel-forms' ),
			__( 'Settings', 'sentinel-forms' ),
			self::CAPABILITY,
			'sentinel-settings',
			[ SettingsPage::class, 'render' ]
		);

		add_submenu_page(
			'sentinel-forms',
			__( 'Diagnostics', 'sentinel-forms' ),
			__( 'Diagnostics', 'sentinel-forms' ),
			self::CAPABILITY,
			'sentinel-diagnostics',
			[ DiagnosticsPage::class, 'render' ]
		);
	}
}
