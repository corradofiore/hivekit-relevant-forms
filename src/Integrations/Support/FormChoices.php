<?php
/**
 * Shared form list for the builder integrations.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Support;

use RelevantForms\Forms\FormRepository;

/**
 * Builds the picker choices every builder integration exposes: all
 * non-archived forms, drafts labelled (only published forms render on the
 * front end).
 */
final class FormChoices {

	/**
	 * Form ID => label. When no forms exist, a single '' => placeholder entry.
	 *
	 * @return array<int|string, string>
	 */
	public static function all(): array {
		$choices = [];

		foreach ( ( new FormRepository() )->all() as $form ) {
			if ( 'archived' === $form->status ) {
				continue;
			}

			$label = $form->name;

			if ( 'published' !== $form->status ) {
				$label .= ' (' . __( 'draft', 'relevant-forms' ) . ')';
			}

			$choices[ (int) $form->id ] = $label;
		}

		if ( [] === $choices ) {
			return [ '' => __( 'No forms yet — create one under Relevant Forms.', 'relevant-forms' ) ];
		}

		return $choices;
	}

	/**
	 * The same list with a leading "Select a form" placeholder — unless the
	 * list is the no-forms hint, which already is a single '' entry.
	 *
	 * @return array<int|string, string>
	 */
	public static function withPlaceholder(): array {
		$choices = self::all();

		if ( [ '' ] === array_keys( $choices ) ) {
			return $choices;
		}

		return [ '' => __( 'Select a form', 'relevant-forms' ) ] + $choices;
	}
}
