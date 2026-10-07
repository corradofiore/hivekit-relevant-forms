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
	 * Field labels — the field name above the control, plus the display
	 * headings that read like it (section titles, content headings, page
	 * titles, the entry-preview heading and its row labels).
	 *
	 * The three text groups below are styled independently by the builder
	 * "Text styles" panels: a radio option must not have to look like the
	 * field name above it.
	 *
	 * Each selector is a single compound selector: the list lives inside one
	 * `:is()`, so the top level has no comma (the builder APIs take one
	 * selector per control and the instance scope class is prepended as-is).
	 * `:is()` adopts the specificity of its strongest argument, so the rules
	 * still beat the stylesheet's own label styles.
	 */
	public const LABELS = '.relevant-forms :is(.relevant-forms-field > label, .relevant-forms-section-title, .relevant-forms-content-title, .relevant-forms-page-title, .relevant-forms-preview-heading, .relevant-forms-preview-label)';

	/**
	 * Captions: the supporting text under a field (help text), the wizard's
	 * step indicator and the entry-preview's empty state. Deliberately
	 * excluded: error messages (they keep the site's error tone) and authored
	 * content blocks (their own HTML decides).
	 */
	public const CAPTIONS = '.relevant-forms :is(.relevant-forms-help, .relevant-forms-page-indicator, .relevant-forms-preview-empty)';

	/**
	 * Option labels: the text beside each radio, checkbox and consent box.
	 */
	public const OPTIONS = '.relevant-forms .relevant-forms-choice label';

	/**
	 * The form's buttons: the submit button plus the multi-page wizard's
	 * Back/Next buttons. They all carry WordPress's `wp-element-button`
	 * class, so the builder button controls target that class scoped to
	 * the form — buttons outside the form are never affected.
	 */
	public const BUTTONS = '.relevant-forms .wp-element-button';
}
