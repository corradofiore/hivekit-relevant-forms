<?php
/**
 * Shared base for payment field types.
 *
 * Payment amounts are always recomputed server-side from the field
 * definitions: choice controls submit the choice POSITION (0-based), never
 * a price, so posted values cannot tamper with the total. Files that are not
 * payment fields are ignored by the aggregation, which lives in
 * RelevantForms\Payments\PaymentTotals.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

use RelevantForms\Payments\Money;

/**
 * Priced choices, position lookups and per-field contribution math.
 */
abstract class PaymentField implements FieldType, ChoiceFieldType {

	use ChoiceTrait;

	/**
	 * Currency of a definition (form currency defaulting rules apply).
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	protected function currency( array $definition ): string {
		return Money::normalizeCurrency( $definition['currency'] ?? Money::DEFAULT_CURRENCY );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Priced rows keep the item first — "Item|Price" — with the price as the
	 * value; regular choices read "Value|Label".
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @return array<int, array{label: string, value: string}>
	 */
	public function normalizeChoices( array $definition ): array {
		return $this->parseChoiceRows( $definition, false );
	}

	/**
	 * Choices with normalized prices.
	 *
	 * The choice textarea stores `Label|Price` lines; the normalized "value"
	 * is the price in major units. Entries keep plain positions.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @return array<int, array{label: string, value: string, priceMinor: int|null}>
	 */
	protected function pricedChoices( array $definition ): array {
		$currency = $this->currency( $definition );
		$priced   = [];

		foreach ( $this->normalizeChoices( $definition ) as $index => $choice ) {
			$priceMinor = Money::toMinor( $choice['value'], $currency );

			$priced[] = [
				'label'      => $choice['label'],
				'value'      => (string) $index,
				'priceMinor' => null !== $priceMinor && $priceMinor > 0 ? $priceMinor : null,
			];
		}

		return $priced;
	}

	/**
	 * Price for a posted position, or null when invalid/unpriced.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param mixed                $position   Posted position.
	 */
	protected function priceForPosition( array $definition, mixed $position ): ?int {
		if ( ! is_scalar( $position ) || ! preg_match( '/^\d+$/', (string) $position ) ) {
			return null;
		}

		$choices = $this->pricedChoices( $definition );
		$index   = (int) $position;

		return $choices[ $index ]['priceMinor'] ?? null;
	}

	/**
	 * Option text with the formatted price appended.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $label      Choice label.
	 * @param int|null             $priceMinor Price in minor units.
	 */
	protected function optionLabel( array $definition, string $label, ?int $priceMinor ): string {
		if ( null === $priceMinor ) {
			return $label;
		}

		return $label . ' (' . Money::format( $priceMinor, $this->currency( $definition ) ) . ')';
	}

	/**
	 * Sum the prices of posted positions (string or array of strings).
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param mixed                $raw        Posted value(s).
	 */
	protected function sumPositionsMinor( array $definition, mixed $raw ): int {
		$positions = is_array( $raw ) ? $raw : [ $raw ];
		$total     = 0;

		foreach ( $positions as $position ) {
			$total += $this->priceForPosition( $definition, $position ) ?? 0;
		}

		return $total;
	}

	/**
	 * Resolve posted positions to their human labels (entry storage).
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param mixed                $raw        Posted value(s).
	 * @return array<int, string>
	 */
	protected function labelsForPositions( array $definition, mixed $raw ): array {
		$positions = is_array( $raw ) ? $raw : [ $raw ];
		$choices   = $this->pricedChoices( $definition );
		$labels    = [];

		foreach ( $positions as $position ) {
			if ( ! is_scalar( $position ) || ! preg_match( '/^\d+$/', (string) $position ) ) {
				continue;
			}

			$index = (int) $position;

			if ( isset( $choices[ $index ] ) ) {
				$labels[] = $choices[ $index ]['label'];
			}
		}

		return $labels;
	}

	/**
	 * Line items for posted choice positions (one item per selection).
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param mixed                $raw        Posted value(s).
	 * @return array<int, array{detail: string, amount_minor: int}>
	 */
	protected function choiceItemsForPositions( array $definition, mixed $raw ): array {
		$positions = is_array( $raw ) ? $raw : [ $raw ];
		$choices   = $this->pricedChoices( $definition );
		$items     = [];

		foreach ( $positions as $position ) {
			if ( ! is_scalar( $position ) || ! preg_match( '/^\d+$/', (string) $position ) ) {
				continue;
			}

			$index = (int) $position;

			if ( ! isset( $choices[ $index ] ) ) {
				continue;
			}

			$items[] = [
				'detail'       => $choices[ $index ]['label'],
				'amount_minor' => $choices[ $index ]['priceMinor'] ?? 0,
			];
		}

		return $items;
	}

	/**
	 * Minor-unit contribution of this field for a raw posted value.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param mixed                $raw        Raw posted value.
	 */
	abstract public function contributionMinor( array $definition, mixed $raw ): int;

	/**
	 * Human-readable line items for one submission (checkout sessions and
	 * the `_payment` entry meta).
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param mixed                $raw        Raw posted value.
	 * @return array<int, array{detail: string, amount_minor: int}>
	 */
	abstract public function itemsForRaw( array $definition, mixed $raw ): array;

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return [
			'required'  => false,
			'help_text' => '',
		];
	}
}
