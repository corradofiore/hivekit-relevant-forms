<?php
/**
 * CSS selectors shared by the website-builder integrations.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Support;

/**
 * Canonical CSS selectors for the form's text-like controls.
 *
 * The field styling controls offered by the builder integrations (Divi,
 * Beaver Builder, Elementor, …) all target the same elements: the text-like
 * inputs inside `.relevant-forms-field` wrappers. Checkboxes and radios are
 * excluded because their `input` is a dimensioned box, not a text control —
 * styling it like a text field would break its layout.
 */
final class FieldSelectors {

	/**
	 * The text-like input fields (`input`, `select`, `textarea`).
	 */
	public const FIELDS = '.relevant-forms-field :is(input, select, textarea):not([type=checkbox]):not([type=radio])';

	/**
	 * The placeholder text of the text-like input fields.
	 */
	public const FIELDS_PLACEHOLDER = self::FIELDS . '::placeholder';
}
