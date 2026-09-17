<?php
/**
 * Request-scoped container for front-end submission feedback (validation
 * errors and entered values) so the shortcode renderer can show them
 * inline on the page the form was submitted from.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms;

/**
 * Stashes per-form submission feedback for the current request only.
 * WordPress shortcodes re-render during the same request, so the form
 * submitted by the visitor picks the errors up automatically.
 */
final class SubmissionFeedback {

	/**
	 * Validation errors keyed by form id, then field id.
	 *
	 * @var array<int, array<string, string>>
	 */
	private static array $errors = [];

	/**
	 * Raw entered values keyed by form id (preserved for re-rendering).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $values = [];

	/**
	 * Remember the failed attempt for one form.
	 *
	 * @param array<string, string> $errors Field id → message.
	 * @param array<string, mixed>  $values Raw submitted values.
	 */
	public static function stash( int $formId, array $errors, array $values ): void {
		self::$errors[ $formId ] = $errors;
		self::$values[ $formId ] = $values;
	}

	/**
	 * Errors recorded for a form in this request.
	 *
	 * @return array<string, string>
	 */
	public static function errors( int $formId ): array {
		return self::$errors[ $formId ] ?? [];
	}

	/**
	 * Values recorded for a form in this request.
	 *
	 * @return array<string, mixed>
	 */
	public static function values( int $formId ): array {
		return self::$values[ $formId ] ?? [];
	}

	/**
	 * Forget everything (optionally just one form). Used between requests
	 * and in tests.
	 */
	public static function reset( ?int $formId = null ): void {
		if ( null === $formId ) {
			self::$errors = [];
			self::$values = [];

			return;
		}

		unset( self::$errors[ $formId ], self::$values[ $formId ] );
	}
}
