<?php
/**
 * Conditional field visibility rules.
 *
 * Relevant Forms' own engine for per-field visibility groups. The semantics
 * mirror what users expect from WPForms conditional logic — groups OR'ed
 * together, rules AND'ed inside a group, case-insensitive comparisons and a
 * field-level show/hide default — but the implementation is independent.
 *
 * Storage shape, keyed `visibility` on a field definition:
 *
 *     [
 *         'default' => 'shown'|'hidden',  // State when no group matches.
 *         'groups'  => [
 *             [ [ 'field' => 'f_a', 'operator' => 'is', 'value' => 'yes' ], ... ],
 *             ...
 *         ],
 *     ]
 *
 * A field flips to the opposite of `default` when ANY group matches with
 * ALL of its rules. Configurations without a single complete group are
 * treated as absent (the field is always rendered).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Visibility;

use RelevantForms\Forms\Fields\FieldTypeRegistry;

/**
 * Pure visibility rule helpers: normalization, evaluation and summaries.
 */
final class VisibilityRules {

	/**
	 * Default states.
	 */
	public const DEFAULT_SHOWN  = 'shown';
	public const DEFAULT_HIDDEN = 'hidden';

	/**
	 * Native operator vocabulary (readable tokens; aliases are accepted on
	 * input but normalized to these).
	 *
	 * @var array<int, string>
	 */
	public const OPERATORS = [
		'is',
		'is_not',
		'contains',
		'not_contains',
		'starts_with',
		'ends_with',
		'empty',
		'not_empty',
		'greater_than',
		'less_than',
	];

	/**
	 * Operators that never take a comparison value.
	 *
	 * @var array<int, string>
	 */
	private const VALUELESS_OPERATORS = [ 'empty', 'not_empty' ];

	/**
	 * Source field types that only get presence-style comparisons.
	 *
	 * @var array<int, string>
	 */
	private const CHOICE_SOURCE_TYPES = [ 'select', 'radio', 'checkbox' ];

	/**
	 * Alternative operator spellings accepted when reading rules (legacy
	 * and WPForms spellings; both the tilde and the dollar sign mean
	 * "ends with").
	 *
	 * @var array<string, string>
	 */
	private const OPERATOR_ALIASES = [
		'==' => 'is',
		'!=' => 'is_not',
		'c'  => 'contains',
		'!c' => 'not_contains',
		'^'  => 'starts_with',
		'~'  => 'ends_with',
		'$'  => 'ends_with',
		'e'  => 'empty',
		'!e' => 'not_empty',
		'>'  => 'greater_than',
		'<'  => 'less_than',
	];

	/**
	 * Canonicalize a raw visibility configuration. Returns an empty array
	 * when nothing usable remains (no conditional behaviour).
	 *
	 * @param mixed $raw Raw configuration (POST data, stored schema, import).
	 * @return array{default: string, groups: array<int, array<int, array{field: string, operator: string, value: string}>>}|array{} Normalized configuration.
	 */
	public static function normalize( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$default = self::DEFAULT_HIDDEN === ( $raw['default'] ?? '' ) ? self::DEFAULT_HIDDEN : self::DEFAULT_SHOWN;
		$groups  = [];

		foreach ( (array) ( $raw['groups'] ?? [] ) as $rawGroup ) {
			if ( ! is_array( $rawGroup ) ) {
				continue;
			}

			$group = [];

			foreach ( $rawGroup as $rawRule ) {
				$rule = self::normalizeRule( $rawRule );

				if ( null !== $rule ) {
					$group[] = $rule;
				}
			}

			if ( [] !== $group ) {
				$groups[] = $group;
			}
		}

		if ( [] === $groups ) {
			return [];
		}

		return [
			'default' => $default,
			'groups'  => $groups,
		];
	}

	/**
	 * Whether a field definition carries usable visibility rules.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	public static function hasVisibility( array $definition ): bool {
		return [] !== self::normalize( $definition['visibility'] ?? null );
	}

	/**
	 * Evaluate every field that carries visibility rules.
	 *
	 * @param array<int, array<string, mixed>> $fields  Form field definitions (order irrelevant).
	 * @param array<string, mixed>             $context Raw submitted values keyed by field id.
	 * @return array<string, bool> Field id => visible, for configured fields only.
	 */
	public static function evaluate( array $fields, array $context ): array {
		$knownIds = [];

		foreach ( $fields as $definition ) {
			if ( is_array( $definition ) && isset( $definition['id'] ) ) {
				$knownIds[ (string) $definition['id'] ] = true;
			}
		}

		$result = [];

		foreach ( $fields as $definition ) {
			if ( ! is_array( $definition ) || ! isset( $definition['id'] ) ) {
				continue;
			}

			$fieldId = (string) $definition['id'];
			$config  = self::normalize( $definition['visibility'] ?? null );

			if ( '' === $fieldId || [] === $config ) {
				continue;
			}

			$result[ $fieldId ] = self::isVisible( $config, $knownIds, $context );
		}

		return $result;
	}

	/**
	 * Human-readable summary of the flip condition ("Shown when: ...").
	 * Returns an empty string when the field has no rules.
	 *
	 * @param array<string, mixed>             $definition Field definition.
	 * @param array<int, array<string, mixed>> $fields     Form field definitions (for labels/choices).
	 */
	public static function summary( array $definition, array $fields ): string {
		$config = self::normalize( $definition['visibility'] ?? null );

		if ( [] === $config ) {
			return '';
		}

		$groups = [];

		foreach ( $config['groups'] as $group ) {
			$rules = [];

			foreach ( $group as $rule ) {
				$rules[] = self::ruleSummary( $rule, $fields );
			}

			$groups[] = implode( ' ' . __( 'and', 'relevant-forms' ) . ' ', $rules );
		}

		$condition = implode( ' ' . __( 'or', 'relevant-forms' ) . ' ', $groups );

		if ( self::DEFAULT_HIDDEN === $config['default'] ) {
			/* translators: %s: condition summary. */
			return sprintf( __( 'Shown when: %s', 'relevant-forms' ), $condition );
		}

		/* translators: %s: condition summary. */
		return sprintf( __( 'Hidden when: %s', 'relevant-forms' ), $condition );
	}

	/**
	 * Operators offered for a source field type.
	 *
	 * @param string $sourceType Source field type.
	 * @return array<int, string>
	 */
	public static function operatorsFor( string $sourceType ): array {
		if ( in_array( $sourceType, self::CHOICE_SOURCE_TYPES, true ) ) {
			return [ 'is', 'is_not', 'empty', 'not_empty' ];
		}

		return self::OPERATORS;
	}

	/**
	 * Native operator token => translated label.
	 *
	 * @return array<string, string>
	 */
	public static function operatorLabels(): array {
		return [
			'is'           => __( 'is', 'relevant-forms' ),
			'is_not'       => __( 'is not', 'relevant-forms' ),
			'contains'     => __( 'contains', 'relevant-forms' ),
			'not_contains' => __( 'does not contain', 'relevant-forms' ),
			'starts_with'  => __( 'starts with', 'relevant-forms' ),
			'ends_with'    => __( 'ends with', 'relevant-forms' ),
			'empty'        => __( 'is empty', 'relevant-forms' ),
			'not_empty'    => __( 'is not empty', 'relevant-forms' ),
			'greater_than' => __( 'is greater than', 'relevant-forms' ),
			'less_than'    => __( 'is less than', 'relevant-forms' ),
		];
	}

	/**
	 * Canonicalize one raw rule, or null when unusable.
	 *
	 * @param mixed $rawRule Raw rule.
	 * @return array{field: string, operator: string, value: string}|null
	 */
	private static function normalizeRule( mixed $rawRule ): ?array {
		if ( ! is_array( $rawRule ) ) {
			return null;
		}

		$field    = is_scalar( $rawRule['field'] ?? null ) ? trim( (string) $rawRule['field'] ) : '';
		$operator = self::normalizeOperator( $rawRule['operator'] ?? null );
		$value    = is_scalar( $rawRule['value'] ?? null ) ? (string) $rawRule['value'] : '';

		if ( '' === $field || null === $operator ) {
			return null;
		}

		return [
			'field'    => $field,
			'operator' => $operator,
			'value'    => $value,
		];
	}

	/**
	 * Map a raw operator (native token or alias) to its native token.
	 *
	 * @param mixed $operator Raw operator.
	 */
	private static function normalizeOperator( mixed $operator ): ?string {
		if ( ! is_scalar( $operator ) ) {
			return null;
		}

		$operator = trim( (string) $operator );

		if ( in_array( $operator, self::OPERATORS, true ) ) {
			return $operator;
		}

		return self::OPERATOR_ALIASES[ $operator ] ?? null;
	}

	/**
	 * Visibility for one normalized configuration.
	 *
	 * @param array{default: string, groups: array<int, array<int, array{field: string, operator: string, value: string}>>} $config   Normalized configuration.
	 * @param array<string, bool>                                                                                            $knownIds Field ids present in the form.
	 * @param array<string, mixed>                                                                                           $context  Submitted values.
	 */
	private static function isVisible( array $config, array $knownIds, array $context ): bool {
		$matched = false;

		foreach ( $config['groups'] as $group ) {
			$groupMatched = true;

			foreach ( $group as $rule ) {
				if ( ! self::ruleMatches( $rule, $knownIds, $context ) ) {
					$groupMatched = false;
					break;
				}
			}

			if ( $groupMatched ) {
				$matched = true;
				break;
			}
		}

		return self::DEFAULT_HIDDEN === $config['default'] ? $matched : ! $matched;
	}

	/**
	 * Whether one rule matches the submitted values.
	 *
	 * @param array{field: string, operator: string, value: string} $rule     Rule.
	 * @param array<string, bool>                                   $knownIds Field ids present in the form.
	 * @param array<string, mixed>                                  $context  Submitted values.
	 */
	private static function ruleMatches( array $rule, array $knownIds, array $context ): bool {
		// Rules whose source field no longer exists never match.
		if ( ! isset( $knownIds[ $rule['field'] ] ) ) {
			return false;
		}

		$left  = self::comparable( $context[ $rule['field'] ] ?? '' );
		$right = self::flatten( $rule['value'] );

		return self::compare( $rule['operator'], $left, $right );
	}

	/**
	 * Normalize a submitted value for comparison: single scalar as a
	 * string, multi-value fields as a list of strings.
	 *
	 * @param mixed $value Raw value.
	 * @return string|array<int, string>
	 */
	private static function comparable( mixed $value ): string|array {
		if ( ! is_array( $value ) ) {
			return self::flatten( $value );
		}

		$values = [];

		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$values[] = self::flatten( $item );
			}
		}

		return $values;
	}

	/**
	 * Comparison-ready form of a value: lowercased, trimmed, decoded.
	 *
	 * @param mixed $value Value.
	 */
	private static function flatten( mixed $value ): string {
		return mb_strtolower( trim( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Apply an operator to a comparison-ready left side and a needle.
	 *
	 * @param string               $operator Native operator token.
	 * @param string|array<string> $left     Comparison-ready submitted value(s).
	 * @param string               $right    Comparison-ready rule value.
	 */
	private static function compare( string $operator, string|array $left, string $right ): bool {
		$values = is_array( $left ) ? $left : [ $left ];

		switch ( $operator ) {
			case 'is':
				return self::anyMatch( $values, static fn ( string $value ): bool => $value === $right );

			case 'is_not':
				return ! self::anyMatch( $values, static fn ( string $value ): bool => $value === $right );

			case 'contains':
				return self::anyMatch( $values, static fn ( string $value ): bool => str_contains( $value, $right ) );

			case 'not_contains':
				return ! self::anyMatch( $values, static fn ( string $value ): bool => str_contains( $value, $right ) );

			case 'starts_with':
				return self::anyMatch( $values, static fn ( string $value ): bool => str_starts_with( $value, $right ) );

			case 'ends_with':
				return self::anyMatch( $values, static fn ( string $value ): bool => str_ends_with( $value, $right ) );

			case 'empty':
				return self::isEmpty( $values );

			case 'not_empty':
				return ! self::isEmpty( $values );

			case 'greater_than':
				return self::anyMatch( $values, static fn ( string $value ): bool => self::compareOrder( $value, $right ) > 0 );

			case 'less_than':
				return self::anyMatch( $values, static fn ( string $value ): bool => self::compareOrder( $value, $right ) < 0 );

			default:
				return false;
		}
	}

	/**
	 * All values (of possibly several) are empty after trimming.
	 *
	 * @param array<int, string> $values Comparison-ready values.
	 */
	private static function isEmpty( array $values ): bool {
		foreach ( $values as $value ) {
			if ( '' !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Order two comparison-ready values: numerically when both are complete
	 * numbers ("1,000" included), lexicographically otherwise (so ISO dates
	 * compare correctly).
	 *
	 * @param string $left  Comparison-ready value.
	 * @param string $right Comparison-ready value.
	 */
	private static function compareOrder( string $left, string $right ): int {
		$leftNumber  = self::asNumber( $left );
		$rightNumber = self::asNumber( $right );

		if ( null !== $leftNumber && null !== $rightNumber ) {
			return $leftNumber <=> $rightNumber;
		}

		return strcmp( $left, $right );
	}

	/**
	 * Numeric reading of a value, or null when it is not a complete number.
	 *
	 * @param string $value Comparison-ready value.
	 */
	private static function asNumber( string $value ): ?float {
		$cleaned = preg_replace( '/[^0-9.\-]/', '', $value );

		if ( ! is_string( $cleaned ) || '' === $cleaned || ! is_numeric( $cleaned ) ) {
			return null;
		}

		return (float) $cleaned;
	}

	/**
	 * Whether any comparison-ready value satisfies the predicate.
	 *
	 * @param array<int, string>  $values    Comparison-ready values.
	 * @param callable(string): bool $predicate Predicate.
	 */
	private static function anyMatch( array $values, callable $predicate ): bool {
		foreach ( $values as $value ) {
			if ( $predicate( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Summary sentence for one rule.
	 *
	 * @param array{field: string, operator: string, value: string} $rule   Rule.
	 * @param array<int, array<string, mixed>>                      $fields Form field definitions.
	 */
	private static function ruleSummary( array $rule, array $fields ): string {
		$label    = self::fieldLabel( $rule['field'], $fields );
		$operator = self::operatorLabels()[ $rule['operator'] ] ?? $rule['operator'];

		if ( in_array( $rule['operator'], self::VALUELESS_OPERATORS, true ) ) {
			return $label . ' ' . $operator;
		}

		return $label . ' ' . $operator . ' ' . self::valueLabel( $rule, $fields );
	}

	/**
	 * Label for a source field, or its id when there is none.
	 *
	 * @param string                          $fieldId Field id.
	 * @param array<int, array<string, mixed>> $fields  Form field definitions.
	 */
	private static function fieldLabel( string $fieldId, array $fields ): string {
		foreach ( $fields as $definition ) {
			if ( ! is_array( $definition ) || $fieldId !== (string) ( $definition['id'] ?? '' ) ) {
				continue;
			}

			$label = trim( (string) ( $definition['label'] ?? '' ) );

			return '' !== $label ? $label : $fieldId;
		}

		return $fieldId;
	}

	/**
	 * Display value for a rule: the choice label when the stored value
	 * matches one of the source field's choices, the raw value otherwise.
	 *
	 * @param array{field: string, operator: string, value: string} $rule   Rule.
	 * @param array<int, array<string, mixed>>                      $fields Form field definitions.
	 */
	private static function valueLabel( array $rule, array $fields ): string {
		foreach ( $fields as $definition ) {
			if ( ! is_array( $definition ) || $rule['field'] !== (string) ( $definition['id'] ?? '' ) ) {
				continue;
			}

			$field = FieldTypeRegistry::get( (string) ( $definition['type'] ?? '' ) );

			// Choice definitions are stored raw ("Label|value" rows); let the
			// field type normalize them the same way the renderers do.
			if ( $field && method_exists( $field, 'normalizeChoices' ) ) {
				foreach ( $field->normalizeChoices( $definition ) as $choice ) {
					if ( $choice['value'] === $rule['value'] && '' !== $choice['label'] ) {
						return $choice['label'];
					}
				}
			}
		}

		return $rule['value'];
	}
}
