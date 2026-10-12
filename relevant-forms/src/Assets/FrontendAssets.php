<?php
/**
 * Front-end asset registration.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Assets;

use RelevantForms\Http\EmailCheckController;
use RelevantForms\Support\AssetVersion;
use RelevantForms\Validation\EmailDeliverability;

/**
 * Registers the public form stylesheet. Enqueued lazily by the renderer so
 * ordinary pages are not slowed down (doc §70).
 */
final class FrontendAssets {

	/**
	 * Whether the assets were registered in this request.
	 *
	 * WordPress registers a handle once but *appends* localize data on every
	 * call, and this method runs more than once per request (the block editor
	 * integration registers on `init`, before `wp_enqueue_scripts`). Without
	 * the guard every localized payload would be printed twice.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'registerAssets' ] );
	}

	/**
	 * Register (not enqueue) front-end assets.
	 */
	public static function registerAssets(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		wp_register_style(
			'relevant-forms',
			RELEVANT_FORMS_URL . 'assets/css/frontend.css',
			[],
			AssetVersion::get( 'assets/css/frontend.css' )
		);

		wp_register_script(
			'relevant-forms-upload',
			RELEVANT_FORMS_URL . 'assets/js/frontend-upload.js',
			[],
			AssetVersion::get( 'assets/js/frontend-upload.js' ),
			true
		);

		wp_register_script(
			'relevant-forms-counter',
			RELEVANT_FORMS_URL . 'assets/js/frontend-counter.js',
			[],
			AssetVersion::get( 'assets/js/frontend-counter.js' ),
			true
		);

		wp_register_script(
			'relevant-forms-email-check',
			RELEVANT_FORMS_URL . 'assets/js/frontend-email-check.js',
			[],
			AssetVersion::get( 'assets/js/frontend-email-check.js' ),
			true
		);

		wp_register_script(
			'relevant-forms-conditional',
			RELEVANT_FORMS_URL . 'assets/js/frontend-conditional.js',
			[],
			AssetVersion::get( 'assets/js/frontend-conditional.js' ),
			true
		);

		wp_register_script(
			'relevant-forms-total',
			RELEVANT_FORMS_URL . 'assets/js/frontend-total.js',
			[],
			AssetVersion::get( 'assets/js/frontend-total.js' ),
			true
		);

		wp_register_script(
			'relevant-forms-pages',
			RELEVANT_FORMS_URL . 'assets/js/frontend-pages.js',
			[],
			AssetVersion::get( 'assets/js/frontend-pages.js' ),
			true
		);

		wp_register_script(
			'relevant-forms-preview',
			RELEVANT_FORMS_URL . 'assets/js/frontend-preview.js',
			[],
			AssetVersion::get( 'assets/js/frontend-preview.js' ),
			true
		);

		wp_register_script(
			'relevant-forms-save-resume',
			RELEVANT_FORMS_URL . 'assets/js/frontend-save-resume.js',
			[],
			AssetVersion::get( 'assets/js/frontend-save-resume.js' ),
			true
		);

		/*
		 * Enqueued only on the confirmation view (the renderer returns the
		 * message instead of the form); it drops the success marker from the
		 * URL so a reload shows the form again.
		 */
		wp_register_script(
			'relevant-forms-confirmation',
			RELEVANT_FORMS_URL . 'assets/js/frontend-confirmation.js',
			[],
			AssetVersion::get( 'assets/js/frontend-confirmation.js' ),
			true
		);

		wp_localize_script(
			'relevant-forms-email-check',
			'relevantFormsEmailCheck',
			[
				'endpoint' => admin_url( 'admin-ajax.php' ),
				'action'   => EmailCheckController::ACTION,
				'nonce'    => wp_create_nonce( EmailCheckController::NONCE ),
				'mode'     => EmailDeliverability::mode(),
				'strings'  => [
					'checking' => __( 'Checking this address…', 'relevant-forms' ),
				],
			]
		);

		wp_localize_script(
			'relevant-forms-save-resume',
			'relevantFormsSaveResume',
			[
				'endpoint' => admin_url( 'admin-post.php' ),
				'action'   => 'relevant_forms_save_resume',
				'strings'  => [
					'title'         => __( 'Save and Resume', 'relevant-forms' ),
					'intro'         => __( 'Enter your email address and we will send you a link to pick up where you left off.', 'relevant-forms' ),
					'emailLabel'    => __( 'Email address', 'relevant-forms' ),
					'save'          => __( 'Email me the link', 'relevant-forms' ),
					'cancel'        => __( 'Cancel', 'relevant-forms' ),
					'saving'        => __( 'Saving…', 'relevant-forms' ),
					'invalidEmail'  => __( 'Enter a valid email address.', 'relevant-forms' ),
					'failed'        => __( 'The progress could not be saved. Please try again.', 'relevant-forms' ),
					'draftSaved'    => __( 'Draft saved in this browser', 'relevant-forms' ),
					/* translators: %s: date and time the draft was stored */
					'draftRestored' => __( 'We restored your saved draft (%s).', 'relevant-forms' ),
					'startFresh'    => __( 'Start fresh', 'relevant-forms' ),
				],
			]
		);
	}
}
