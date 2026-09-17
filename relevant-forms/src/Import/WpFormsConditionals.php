<?php
/**
 * WPForms conditional logic conversion (branching phase 4).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Import;

/**
 * Converts WPForms field conditionals ("Smart Logic") into Relevant
 * visibility rules. Pure: no WordPress calls beyond translation, fully
 * unit-testable.
 *
 * WPForms semantics (verified against the installed 2.0.2 source):
 *
 * - `conditional_type` is field-level: `show` (visible when the conditions
 *   match) or `hide` (hidden when they match). The Relevant `default` is the
 *   NOT-matched state, so the mapping inverts.
 * - `conditionals` is a map of groups; a group is a map of rules; a rule is
 *   { field, operator, value }. Groups are OR'ed, rules inside a group are
 *   AND'ed. Empty-field rules disappear (clear_empty_rules()) and an input
 *   without a single complete group counts as "matched", which makes a
 *   hide-type field always hidden there.
 * - For choice sources (select/radio/checkbox) the rule value is the CHOICE
 *   KEY (usually 1-based) that WPForms compares against the submitted
 *   labels. Relevant compares submitted choice VALUES, so keys are
 *   translated through the imported choices.
 */
final class WpFormsConditionals {

	/**
	 * Operator mapping for value-based sources (text, email, number, ...).
	 *
	 * `c`, `^`, `~` and `!c` are legacy spellings that WPForms still accepts.
	 *
	 * @var array<string, string>
	 */
	public const VALUE_OPERATOR_MAP = [
		'==' => 'is',
		'!=' => 'is_not',
		'c'  => 'contains',
		'!c' => 'not_contains',
		'^'  => 'starts_with',
		'~'  => 'ends_with',
		'e'  => 'empty',
		'!e' => 'not_empty',
		'>'  => 'greater_than',
		'<'  => 'less_than',
	];

	/**
	 * Operator mapping for choice sources (select/radio/checkbox).
	 *
	 * Only the four current operators exist there; the legacy spellings
	 * compare as equality in WPForms and map accordingly.
	 *
	 * @var array<string, string>
	 */
	public const CHOICE_OPERATOR_MAP = [
		'==' => 'is',
		'c'  => 'is',
		'^'  => 'is',
		'~'  => 'is',
		'!=' => 'is_not',
		'!c' => 'is_not',
		'e'  => 'empty',
		'!e' => 'not_empty',
	];

	/**
	 * Choice-based source types (rule values are choice keys).
	 *
	 * @var array<int, string>
	 */
	private const CHOICE_SOURCE_TYPES = [ 'select', 'radio', 'checkbox' ];

	/**
	 * Operators that carry no value in the Relevant engine.
	 *
	 * @var array<int, string>
	 */
	private const VALUELESS_OPERATORS = [ 'empty', 'not_empty' ];

	/**
	 * Convert one WPForms field's conditionals into a visibility config.
	 *
	 * @param array<string, mixed>                $field       Raw WPForms field (may carry `conditional_type` and `conditionals`).
	 * @param array<string, array<string, mixed>> $rawById     All raw WPForms fields keyed by field id (choice-key resolution).
	 * @param array<string, array<string, mixed>> $definitions Imported definitions keyed by field id (conversion targets).
	 * @return array{visibility: array<string, mixed>, notes: array<int, string>}
	 */
	public static function convert( array $field, array $rawById, array $definitions ): array {
		$result = [
			'visibility' => [],
			'notes'      => [],
		];

		$conditionals = $field['conditionals'] ?? null;

		if ( ! is_array( $conditionals ) ) {
			return $result;
		}

		$type     = strtolower( trim( (string) ( $field['conditional_type'] ?? '' ) ) );
		$groups   = [];
		$dropped  = 0;
		$hadRules = false;

		foreach ( $conditionals as $rawGroup ) {
			if ( ! is_array( $rawGroup ) ) {
				continue;
			}

			$group = [];

			foreach ( $rawGroup as $rawRule ) {
				if ( ! is_array( $rawRule ) ) {
					continue;
				}

				// WPForms clear_empty_rules(): a rule without a selected
				// source field disappears without breaking the group.
				if ( '' === trim( (string) ( $rawRule['field'] ?? '' ) ) ) {
					continue;
				}

				$hadRules = true;

				$rule = self::rule( $rawRule, $rawById, $definitions );

				if ( null === $rule ) {
					// One unmappable rule drops the whole group: keeping the
					// rest would weaken the AND semantics.
					$group = [];
					++$dropped;
					break;
				}

				$group[] = $rule;
			}

			if ( [] !== $group ) {
				$groups[] = $group;
			}
		}

		if ( [] !== $groups ) {
			$result['visibility'] = [
				'default' => 'hide' === $type ? 'shown' : 'hidden',
				'groups'  => $groups,
			];
		}

		if ( $dropped > 0 ) {
			$result['notes'][] = sprintf(
				/* translators: %d: number of conditional rule groups. */
				_n(
					'%d conditional rule group could not be imported and was dropped.',
					'%d conditional rule groups could not be imported and were dropped.',
					$dropped,
					'relevant-forms'
				),
				$dropped
			);

			if ( [] === $groups ) {
				$result['notes'][] = __( 'The field is imported without conditions (always visible).', 'relevant-forms' );
			}

			return $result;
		}

		if ( ! $hadRules && 'hide' === $type ) {
			// No complete rule in the input: WPForms counts that as matched,
			// so the field was always hidden there. Visibility cannot express
			// "always hidden"; the field is imported as visible.
			$result['notes'][] = __( 'In WPForms this field was always hidden (its conditional rules were empty); it was imported as always visible.', 'relevant-forms' );
		}

		return $result;
	}

	/**
	 * Convert one rule, or null when it cannot be faithfully imported.
	 *
	 * @param array<string, mixed>                $rawRule     Raw WPForms rule.
	 * @param array<string, array<string, mixed>> $rawById     All raw WPForms fields keyed by field id.
	 * @param array<string, array<string, mixed>> $definitions Imported definitions keyed by field id.
	 * @return array{field: string, operator: string, value: string}|null
	 */
	private static function rule( array $rawRule, array $rawById, array $definitions ): ?array {
		$fieldId = trim( (string) ( $rawRule['field'] ?? '' ) );

		if ( '' === $fieldId || ! isset( $definitions[ $fieldId ] ) || ! is_array( $definitions[ $fieldId ] ) ) {
			return null;
		}

		$operator = trim( (string) ( $rawRule['operator'] ?? '' ) );
		$value    = is_scalar( $rawRule['value'] ?? null ) ? (string) $rawRule['value'] : '';

		$type = strtolower( (string) ( $definitions[ $fieldId ]['type'] ?? '' ) );

		if ( in_array( $type, self::CHOICE_SOURCE_TYPES, true ) ) {
			$native = self::CHOICE_OPERATOR_MAP[ $operator ] ?? null;

			if ( null === $native ) {
				return null;
			}

			if ( in_array( $native, self::VALUELESS_OPERATORS, true ) ) {
				return [
					'field'    => $fieldId,
					'operator' => $native,
					'value'    => '',
				];
			}

			$choice = self::choiceValueByKey( $value, $rawById[ $fieldId ] ?? null );

			if ( null === $choice ) {
				return null;
			}

			return [
				'field'    => $fieldId,
				'operator' => $native,
				'value'    => $choice,
			];
		}

		$native = self::VALUE_OPERATOR_MAP[ $operator ] ?? null;

		if ( null === $native ) {
			return null;
		}

		if ( in_array( $native, self::VALUELESS_OPERATORS, true ) ) {
			return [
				'field'    => $fieldId,
				'operator' => $native,
				'value'    => '',
			];
		}

		return [
			'field'    => $fieldId,
			'operator' => $native,
			'value'    => $value,
		];
	}

	/**
	 * Resolve a WPForms choice-key rule value to the imported choice value.
	 *
	 * @param string               $ruleValue   Raw rule value (the choice key).
	 * @param array<string, mixed> $sourceField Raw WPForms source field or null.
	 */
	private static function choiceValueByKey( string $ruleValue, ?array $sourceField ): ?string {
		$choices = $sourceField['choices'] ?? null;

		if ( ! is_array( $choices ) ) {
			return null;
		}

		// WPForms compares `(int) $rule_value` against the choice keys.
		$needle = (int) $ruleValue;

		foreach ( $choices as $key => $choice ) {
			if ( ! is_array( $choice ) || (int) $key !== $needle ) {
				continue;
			}

			$value = WpFormsFieldMapper::choiceValue( $choice );

			return '' !== $value ? $value : null;
		}

		return null;
	}
}
