<?php
/**
 * Admin asset registration.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Assets;

use RelevantForms\Admin\Controllers\RedirectTargetSearchController;
use RelevantForms\Admin\Controllers\SmsVerificationController;
use RelevantForms\Forms\Visibility\VisibilityRules;
use RelevantForms\Support\AssetVersion;

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
	 * Enqueue on Relevant Forms admin pages only.
	 *
	 * @param string $hookSuffix Current admin page hook.
	 */
	public static function enqueue( string $hookSuffix ): void {
		if ( false === strpos( $hookSuffix, 'relevant-' ) ) {
			return;
		}

		wp_enqueue_style(
			'relevant-forms-admin',
			RELEVANT_FORMS_URL . 'assets/css/admin.css',
			[],
			AssetVersion::get( 'assets/css/admin.css' )
		);

		if ( false !== strpos( $hookSuffix, 'relevant-form-edit' ) ) {
			// The field editor previews render the real front-end markup,
			// so they get the real front-end stylesheet.
			wp_enqueue_style(
				'relevant-forms-preview',
				RELEVANT_FORMS_URL . 'assets/css/frontend.css',
				[],
				AssetVersion::get( 'assets/css/frontend.css' )
			);
		}

		wp_enqueue_script(
			'relevant-forms-admin',
			RELEVANT_FORMS_URL . 'assets/js/admin.js',
			[],
			AssetVersion::get( 'assets/js/admin.js' ),
			true
		);

		wp_localize_script(
			'relevant-forms-admin',
			'relevantFormsAdmin',
			[
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'searchNonce'         => wp_create_nonce( RedirectTargetSearchController::ACTION ),
				'noResults'           => __( 'No pages or posts found.', 'relevant-forms' ),
				'smsSendNonce'        => wp_create_nonce( SmsVerificationController::ACTION_SEND ),
				'smsVerifyNonce'      => wp_create_nonce( SmsVerificationController::ACTION_VERIFY ),
				'smsStrings'          => [
					'checkPhone'    => __( 'Enter the phone number first.', 'relevant-forms' ),
					'sending'       => __( 'Sending code…', 'relevant-forms' ),
					'codeSent'      => __( 'Code sent. Enter the 6 digits we texted you.', 'relevant-forms' ),
					'verified'      => __( 'Verified', 'relevant-forms' ),
					'unverified'    => __( 'Not verified — send and verify the code before saving.', 'relevant-forms' ),
					'devCode'       => __( 'Dev code:', 'relevant-forms' ),
					'requestFailed' => __( 'The request failed. Try again.', 'relevant-forms' ),
				],
				'fieldStrings'        => [
					'untitled' => __( 'Untitled field', 'relevant-forms' ),
				],
				'visibilityStrings'   => [
					/* translators: %s: condition summary ("Email is not empty"). */
					'shown'       => __( 'Shown when: %s', 'relevant-forms' ),
					/* translators: %s: condition summary ("Email is not empty"). */
					'hidden'      => __( 'Hidden when: %s', 'relevant-forms' ),
					'and'         => __( 'and', 'relevant-forms' ),
					'or'          => __( 'or', 'relevant-forms' ),
					'notAbove'    => __( 'not above', 'relevant-forms' ),
					'selectField' => __( '— Select field —', 'relevant-forms' ),
					'selectValue' => __( '— Select value —', 'relevant-forms' ),
				],
				'visibilityOperators' => VisibilityRules::operatorLabels(),
				'choiceOperators'     => VisibilityRules::operatorsFor( 'select' ),
			]
		);
	}
}
