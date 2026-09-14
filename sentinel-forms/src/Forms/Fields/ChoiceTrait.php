<?php
/**
 * Choice handling shared by select, radio and checkbox fields.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Forms\Fields;

/**
 * Normalizes choice definitions into label/value pairs.
 */
trait ChoiceTrait {

	/**
	 * Normalize a choices definition.
	 *
	 * Accepts an array of ['label' =>, 'value' =>] maps or plain strings
	 * ("Label" or "Label|Value").
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @return array<int, array{label: string, value: string}>
	 */
	public function normalizeChoices( array $definition ): array {
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
				$label = trim( $parts[0] );
				$value = trim( $parts[1] ?? $parts[0] );
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
