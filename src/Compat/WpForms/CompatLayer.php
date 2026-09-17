<?php
/**
 * WPForms compatibility layer (Phase 7.5, docs/wpforms-compatibility.md).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Compat\WpForms;

use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Import\WpFormsScanner;

/**
 * Fires WPForms-shaped submission hooks for third-party consumers
 * (Automator, Newsletter, …) while WPForms itself is absent. The native
 * `relevant_forms_*` hooks remain the canonical API.
 */
final class CompatLayer {

	/**
	 * Compatibility surface version (manifest).
	 */
	public const COMPAT_VERSION = '1.0';

	/**
	 * Shared accessor instance.
	 *
	 * @var Accessor|null
	 */
	private static ?Accessor $accessor = null;

	/**
	 * Register the layer when WPForms is absent.
	 */
	public static function register(): void {
		if ( ! self::shouldRegister() ) {
			return;
		}

		require_once __DIR__ . '/functions.php';

		add_filter( 'relevant_forms_pre_insert_errors', [ self::class, 'process' ], 10, 3 );
		add_action( 'relevant_forms_submission_received', [ self::class, 'complete' ], 10, 3 );

		do_action( 'relevant_forms_wpforms_compat_loaded', self::COMPAT_VERSION );
	}

	/**
	 * Whether the layer should activate: WPForms absent, unless a site
	 * explicitly overrides via `relevant_forms_wpforms_compat_enabled`.
	 */
	public static function shouldRegister(): bool {
		$default = ! WpFormsScanner::wpFormsActive();

		return (bool) apply_filters( 'relevant_forms_wpforms_compat_enabled', $default );
	}

	/**
	 * Shared accessor (also returned by the wpforms() shim).
	 */
	public static function accessor(): Accessor {
		if ( null === self::$accessor ) {
			self::$accessor = new Accessor();
		}

		return self::$accessor;
	}

	/**
	 * Validation gate: fires `wpforms_process` and converts emulated
	 * errors into Relevant field errors (halting the submission).
	 *
	 * @param array<string, mixed> $errors Existing field errors.
	 * @param Form                 $form   Form entity.
	 * @param array<string, mixed> $values Sanitized values.
	 * @return array<string, mixed>
	 */
	public static function process( array $errors, Form $form, array $values ): array {
		do_action(
			'wpforms_process',
			ShapeTranslator::fields( $form, $values ),
			self::entry(),
			ShapeTranslator::formData( $form )
		);

		$accessor = self::accessor();
		$mapped   = [];

		foreach ( $accessor->process->errors as $fieldId => $fieldErrors ) {
			$message = is_array( $fieldErrors )
				? trim( implode( ' ', array_map( 'strval', $fieldErrors ) ) )
				: trim( (string) $fieldErrors );

			if ( '' !== $message ) {
				$mapped[ (string) $fieldId ] = $message;
			}
		}

		// Reset: the gate is per-submission state.
		$accessor->process->errors = [];

		return array_merge( $errors, $mapped );
	}

	/**
	 * Post-save: fires `wpforms_process_complete` (+ form-id variant)
	 * with WPForms-identical parameters.
	 *
	 * @param int                  $entryId Entry ID.
	 * @param int                  $formId  Relevant form ID.
	 * @param array<string, mixed> $values  Sanitized values.
	 */
	public static function complete( int $entryId, int $formId, array $values ): void {
		$form = ( new FormRepository() )->find( $formId );

		if ( null === $form ) {
			return;
		}

		$fields   = ShapeTranslator::fields( $form, $values );
		$entry    = self::entry();
		$formData = ShapeTranslator::formData( $form );

		do_action( 'wpforms_process_complete', $fields, $entry, $formData, $entryId );
		do_action( 'wpforms_process_complete_' . $formId, $fields, $entry, $formData, $entryId );
	}

	/**
	 * The `$entry` parameter WPForms passes: the original request data.
	 *
	 * @return array<string, mixed>
	 */
	private static function entry(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WPForms hooks receive the raw request array by contract.
		return wp_unslash( $_POST );
	}
}
