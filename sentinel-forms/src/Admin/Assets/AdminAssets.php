<?php
/**
 * Admin asset registration.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Assets;

use SentinelForms\Admin\Controllers\RedirectTargetSearchController;
use SentinelForms\Admin\Controllers\SmsVerificationController;
use SentinelForms\Forms\Visibility\VisibilityRules;
use SentinelForms\Support\AssetVersion;

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
			AssetVersion::get( 'assets/css/admin.css' )
		);

		if ( false !== strpos( $hookSuffix, 'sentinel-form-edit' ) ) {
			// The field editor previews render the real front-end markup,
			// so they get the real front-end stylesheet.
			wp_enqueue_style(
				'sentinel-forms-preview',
				SENTINEL_FORMS_URL . 'assets/css/frontend.css',
				[],
				AssetVersion::get( 'assets/css/frontend.css' )
			);
		}

		wp_enqueue_script(
			'sentinel-forms-admin',
			SENTINEL_FORMS_URL . 'assets/js/admin.js',
			[],
			AssetVersion::get( 'assets/js/admin.js' ),
			true
		);

		wp_localize_script(
			'sentinel-forms-admin',
			'sentinelFormsAdmin',
			[
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'searchNonce'         => wp_create_nonce( RedirectTargetSearchController::ACTION ),
				'noResults'           => __( 'No pages or posts found.', 'sentinel-forms' ),
				'smsSendNonce'        => wp_create_nonce( SmsVerificationController::ACTION_SEND ),
				'smsVerifyNonce'      => wp_create_nonce( SmsVerificationController::ACTION_VERIFY ),
				'smsStrings'          => [
					'checkPhone'    => __( 'Enter the phone number first.', 'sentinel-forms' ),
					'sending'       => __( 'Sending code…', 'sentinel-forms' ),
					'codeSent'      => __( 'Code sent. Enter the 6 digits we texted you.', 'sentinel-forms' ),
					'verified'      => __( 'Verified', 'sentinel-forms' ),
					'unverified'    => __( 'Not verified — send and verify the code before saving.', 'sentinel-forms' ),
					'devCode'       => __( 'Dev code:', 'sentinel-forms' ),
					'requestFailed' => __( 'The request failed. Try again.', 'sentinel-forms' ),
				],
				'fieldStrings'        => [
					'untitled' => __( 'Untitled field', 'sentinel-forms' ),
				],
				'visibilityStrings'   => [
					/* translators: %s: condition summary ("Email is not empty"). */
					'shown'       => __( 'Shown when: %s', 'sentinel-forms' ),
					/* translators: %s: condition summary ("Email is not empty"). */
					'hidden'      => __( 'Hidden when: %s', 'sentinel-forms' ),
					'and'         => __( 'and', 'sentinel-forms' ),
					'or'          => __( 'or', 'sentinel-forms' ),
					'notAbove'    => __( 'not above', 'sentinel-forms' ),
					'selectField' => __( '— Select field —', 'sentinel-forms' ),
					'selectValue' => __( '— Select value —', 'sentinel-forms' ),
				],
				'visibilityOperators' => VisibilityRules::operatorLabels(),
				'choiceOperators'     => VisibilityRules::operatorsFor( 'select' ),
			]
		);
	}
}
