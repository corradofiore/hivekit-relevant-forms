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

	/**
	 * The visitor-facing text OUTSIDE the controls: field labels, radio and
	 * checkbox option labels, help text and the display headings. The form's
	 * "Text styles" controls target exactly these elements, while the
	 * "Field styles" controls keep targeting the controls themselves.
	 *
	 * One `:is()` compound selector holds the whole list, so the top level has
	 * no comma: the builder APIs take a single selector per control
	 * (Elementor) and the instance scope class is prepended to it as-is.
	 * `:is()` adopts the specificity of its strongest argument, so the rule
	 * still beats the stylesheet's own label styles.
	 *
	 * Deliberately excluded: error messages (they stay legible in the site's
	 * error tone) and authored content blocks (their own HTML decides).
	 */
	public const TEXT = '.relevant-forms :is(.relevant-forms-field > label, .relevant-forms-choice label, .relevant-forms-help, .relevant-forms-section-title, .relevant-forms-content-title, .relevant-forms-page-title, .relevant-forms-page-indicator, .relevant-forms-preview-heading, .relevant-forms-preview-label, .relevant-forms-preview-empty)';

	/**
	 * The form's buttons: the submit button plus the multi-page wizard's
	 * Back/Next buttons. They all carry WordPress's `wp-element-button`
	 * class, so the builder button controls target that class scoped to
	 * the form — buttons outside the form are never affected.
	 */
	public const BUTTONS = '.relevant-forms .wp-element-button';
}
