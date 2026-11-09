<?php
/**
 * Admin asset registration.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Assets;

use RelevantForms\Admin\Controllers\FormPreviewController;
use RelevantForms\Admin\Controllers\RedirectTargetSearchController;
use RelevantForms\Admin\Controllers\SmsVerificationController;
use RelevantForms\Admin\Controllers\TestEmailController;
use RelevantForms\Assets\FrontendAssets;
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

			/*
			 * The Form preview tab renders the live form, so the front-end
			 * feature scripts are loaded here too. All of them are enqueued (not
			 * just the ones the stored form needs) because the preview is
			 * refreshed through admin-ajax: injected markup has to find every
			 * script already present to boot it again.
			 *
			 * Save and Resume is deliberately left out: drafts and resume links
			 * have no place in the editor, and the preview omits the attributes
			 * its script binds to.
			 */
			FrontendAssets::registerAssets();

			foreach ( [ 'upload', 'counter', 'conditional', 'total', 'pages', 'preview' ] as $frontendScript ) {
				wp_enqueue_script( 'relevant-forms-' . $frontendScript );
			}
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
				'noResults'           => __( 'No pages or posts found.', 'hivekit-relevant-forms' ),
				'previewNonce'        => wp_create_nonce( FormPreviewController::ACTION ),
				'smsSendNonce'        => wp_create_nonce( SmsVerificationController::ACTION_SEND ),
				'smsVerifyNonce'      => wp_create_nonce( SmsVerificationController::ACTION_VERIFY ),
				'testEmailNonce'      => wp_create_nonce( TestEmailController::ACTION ),
				'smsStrings'          => [
					'checkPhone'    => __( 'Enter the phone number first.', 'hivekit-relevant-forms' ),
					'sending'       => __( 'Sending code…', 'hivekit-relevant-forms' ),
					'codeSent'      => __( 'Code sent. Enter the 6 digits we texted you.', 'hivekit-relevant-forms' ),
					'verified'      => __( 'Verified', 'hivekit-relevant-forms' ),
					'unverified'    => __( 'Not verified — send and verify the code before saving.', 'hivekit-relevant-forms' ),
					'devCode'       => __( 'Dev code:', 'hivekit-relevant-forms' ),
					'requestFailed' => __( 'The request failed. Try again.', 'hivekit-relevant-forms' ),
				],
				'testEmailStrings'    => [
					'sending' => __( 'Sending…', 'hivekit-relevant-forms' ),
					/* translators: %s: recipient email addresses. */
					'sent'    => __( 'Test email sent to %s.', 'hivekit-relevant-forms' ),
					'failed'  => __( 'The test email could not be sent. Check the site\'s mail configuration.', 'hivekit-relevant-forms' ),
				],
				'formSaveStrings'     => [
					'saving' => __( 'Saving…', 'hivekit-relevant-forms' ),
					'saved'  => __( 'Form saved.', 'hivekit-relevant-forms' ),
					'failed' => __( 'The form could not be saved. Please try again.', 'hivekit-relevant-forms' ),
				],
				'formPreviewStrings'  => [
					'refreshing' => __( 'Refreshing…', 'hivekit-relevant-forms' ),
					'updated'    => __( 'Preview updated.', 'hivekit-relevant-forms' ),
					'failed'     => __( 'The preview could not be refreshed. Save the form and try again.', 'hivekit-relevant-forms' ),
				],
				'cloudKeyStrings'     => [
					'edit'   => __( 'Edit', 'hivekit-relevant-forms' ),
					'cancel' => __( 'Cancel', 'hivekit-relevant-forms' ),
				],
				'fieldStrings'        => [
					'untitled' => __( 'Untitled field', 'hivekit-relevant-forms' ),
				],
				'fieldEditorStrings'  => [
					'noDefault'    => __( '— No default —', 'hivekit-relevant-forms' ),
					'notInChoices' => __( 'not in the choices', 'hivekit-relevant-forms' ),
				],
				'visibilityStrings'   => [
					/* translators: %s: condition summary ("Email is not empty"). */
					'shown'       => __( 'Shown when: %s', 'hivekit-relevant-forms' ),
					/* translators: %s: condition summary ("Email is not empty"). */
					'hidden'      => __( 'Hidden when: %s', 'hivekit-relevant-forms' ),
					'and'         => __( 'and', 'hivekit-relevant-forms' ),
					'or'          => __( 'or', 'hivekit-relevant-forms' ),
					'notAbove'    => __( 'not above', 'hivekit-relevant-forms' ),
					'selectField' => __( '— Select field —', 'hivekit-relevant-forms' ),
					'selectValue' => __( '— Select value —', 'hivekit-relevant-forms' ),
				],
				'visibilityOperators' => VisibilityRules::operatorLabels(),
				'choiceOperators'     => VisibilityRules::operatorsFor( 'select' ),
			]
		);
	}
}
