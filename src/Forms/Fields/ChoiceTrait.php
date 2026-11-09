<?php
/**
 * Choice handling shared by select, radio and checkbox fields.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * Normalizes choice definitions into label/value pairs.
 */
trait ChoiceTrait {

	/**
	 * Normalize a choices definition.
	 *
	 * Accepts an array of ['label' =>, 'value' =>] maps or plain strings
	 * ("Value" or "Value|Label").
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @return array<int, array{label: string, value: string}>
	 */
	public function normalizeChoices( array $definition ): array {
		return $this->parseChoiceRows( $definition, true );
	}

	/**
	 * Parse raw choice rows into label/value pairs.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param bool                 $valueFirst Whether string rows put the value first ("Value|Label",
	 *                                         the regular-choice format). Priced choices override
	 *                                         normalizeChoices with false: their pipes separate the
	 *                                         item (label) from the price (value).
	 * @return array<int, array{label: string, value: string}>
	 */
	protected function parseChoiceRows( array $definition, bool $valueFirst ): array {
		$choices = [];
		$raw     = $definition['choices'] ?? [];

		if ( ! is_array( $raw ) ) {
			return $choices;
		}

		foreach ( $raw as $item ) {
			if ( is_array( $item ) ) {
				$label = trim( (string) ( $item['label'] ?? '' ) );
				$value = trim( (string) ( $item['value'] ?? $label ) );
			} else {
				$item  = trim( (string) $item );
				$parts = explode( '|', $item, 2 );
				$first = trim( $parts[0] );
				$last  = trim( $parts[1] ?? $parts[0] );

				$value = $valueFirst ? $first : $last;
				$label = $valueFirst ? $last : $first;
			}

			if ( '' === $label ) {
				continue;
			}

			$choices[] = [
				'label' => $label,
				'value' => $value,
			];
		}

		return $choices;
	}

	/**
	 * Allowed values of the choice definition.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @return array<int, string>
	 */
	protected function allowedValues( array $definition ): array {
		return array_values(
			array_unique(
				array_map(
					static fn ( array $choice ): string => $choice['value'],
					$this->normalizeChoices( $definition )
				)
			)
		);
	}
}
