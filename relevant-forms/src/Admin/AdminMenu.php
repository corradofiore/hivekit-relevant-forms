<?php
/**
 * Admin menu registration.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin;

use RelevantForms\Admin\Pages\DiagnosticsPage;
use RelevantForms\Admin\Pages\EntryListPage;
use RelevantForms\Admin\Pages\EntryViewPage;
use RelevantForms\Admin\Pages\FormEditPage;
use RelevantForms\Admin\Pages\FormListPage;
use RelevantForms\Admin\Pages\ImportPage;
use RelevantForms\Admin\Pages\SettingsPage;
use RelevantForms\Forms\FormRepository;

/**
 * Registers the Relevant Forms menu and submenu pages.
 */
final class AdminMenu {

	/**
	 * Capability required to manage Relevant Forms.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Register the admin_menu hook.
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'build' ] );

		/*
		 * The access check for unknown admin pages runs while the menu loads,
		 * before `admin_init`, so the redirect has to happen on `admin_menu`.
		 */
		add_action( 'admin_menu', [ self::class, 'redirectLegacyEditorSlug' ], 0 );
	}

	/**
	 * Build the menu.
	 */
	public static function build(): void {
		add_menu_page(
			__( 'Relevant Forms', 'relevant-forms' ),
			__( 'Relevant Forms', 'relevant-forms' ),
			self::CAPABILITY,
			'relevant-forms',
			[ FormListPage::class, 'render' ],
			'dashicons-email-alt',
			30
		);

		add_submenu_page(
			'relevant-forms',
			__( 'Forms', 'relevant-forms' ),
			__( 'Forms', 'relevant-forms' ),
			self::CAPABILITY,
			'relevant-forms',
			[ FormListPage::class, 'render' ]
		);

		/*
		 * The editor is reached from the Forms screen, which carries the
		 * primary "Add new form" button, so it gets no menu item of its own.
		 * The entry must stay registered while the request is routed (access
		 * check and dispatch); it is dropped in the `parent_file` filter,
		 * right before the menu is rendered.
		 */
		$editor_hook = add_submenu_page(
			'relevant-forms',
			__( 'Edit Form', 'relevant-forms' ),
			__( 'Edit Form', 'relevant-forms' ),
			self::CAPABILITY,
			'relevant-form-edit',
			[ FormEditPage::class, 'render' ]
		);

		add_filter( 'parent_file', [ self::class, 'highlightEditorParent' ] );
		add_filter( 'submenu_file', [ self::class, 'highlightEditorSubmenu' ], 10, 2 );
		add_action( 'load-' . (string) $editor_hook, [ self::class, 'setEditorPageTitle' ] );

		add_submenu_page(
			'relevant-forms',
			__( 'Entries', 'relevant-forms' ),
			__( 'Entries', 'relevant-forms' ),
			self::CAPABILITY,
			'relevant-entries',
			[ EntryListPage::class, 'render' ]
		);

		// Hidden page: single entry view.
		add_submenu_page(
			'',
			__( 'Entry', 'relevant-forms' ),
			__( 'Entry', 'relevant-forms' ),
			self::CAPABILITY,
			'relevant-entry',
			[ EntryViewPage::class, 'render' ]
		);

		add_submenu_page(
			'relevant-forms',
			__( 'Import', 'relevant-forms' ),
			__( 'Import', 'relevant-forms' ),
			self::CAPABILITY,
			'relevant-import',
			[ ImportPage::class, 'render' ]
		);

		add_submenu_page(
			'relevant-forms',
			__( 'Settings', 'relevant-forms' ),
			__( 'Settings', 'relevant-forms' ),
			self::CAPABILITY,
			'relevant-settings',
			[ SettingsPage::class, 'render' ]
		);

		add_submenu_page(
			'relevant-forms',
			__( 'Diagnostics', 'relevant-forms' ),
			__( 'Diagnostics', 'relevant-forms' ),
			self::CAPABILITY,
			'relevant-diagnostics',
			[ DiagnosticsPage::class, 'render' ]
		);
	}

	/**
	 * Keep the Relevant Forms menu highlighted on the hidden editor page.
	 *
	 * Runs from `wp-admin/menu-header.php` just before the menu is output:
	 * that is the earliest point where the editor entry is no longer needed
	 * by the request routing.
	 *
	 * @param string|null $parent_file Current parent file.
	 */
	public static function highlightEditorParent( ?string $parent_file ): string {
		remove_submenu_page( 'relevant-forms', 'relevant-form-edit' );

		return self::isEditorScreen() ? 'relevant-forms' : (string) $parent_file;
	}

	/**
	 * Keep the "Forms" submenu item highlighted on the hidden editor page.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @param string|null $parent_file  Current parent file.
	 */
	public static function highlightEditorSubmenu( ?string $submenu_file, ?string $parent_file = '' ): string {
		unset( $parent_file );

		return self::isEditorScreen() ? 'relevant-forms' : (string) $submenu_file;
	}

	/**
	 * Browser-tab title for the hidden editor page.
	 */
	public static function setEditorPageTitle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$form_id = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;

		$title = __( 'Add New Form', 'relevant-forms' );

		if ( $form_id > 0 ) {
			$form      = ( new FormRepository() )->find( $form_id );
			$form_name = null !== $form ? trim( $form->name ) : '';
			$title     = '' !== $form_name
				? sprintf(
					/* translators: %s: form name. */
					__( 'Edit Form: %s', 'relevant-forms' ),
					$form_name
				)
				: __( 'Edit Form', 'relevant-forms' );
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- hidden pages need the title global set manually.
		$GLOBALS['title'] = $title;
	}

	/**
	 * Redirect the previous editor slug to the new one, so old bookmarks
	 * and open tabs keep working.
	 */
	public static function redirectLegacyEditorSlug(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		if ( ! isset( $_GET['page'] ) || 'relevant-form-new' !== sanitize_key( wp_unslash( (string) $_GET['page'] ) ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$args = [ 'page' => 'relevant-form-edit' ];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$form_id = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;

		if ( $form_id > 0 ) {
			$args['form_id'] = $form_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Whether the current request is the (hidden) form editor page.
	 */
	private static function isEditorScreen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) && 'relevant-form-edit' === sanitize_key( wp_unslash( (string) $_GET['page'] ) );
	}
}
