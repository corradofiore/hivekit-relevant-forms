<?php
/**
 * Payment totals aggregation.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Payments;

use RelevantForms\Forms\Fields\FieldTypeRegistry;
use RelevantForms\Forms\Fields\PaymentField;

/**
 * Server-authoritative total for one submission.
 *
 * Every amount comes from the field definitions (fixed amounts, priced
 * choices resolved by position, parsed donation amounts); posted prices are
 * never trusted.
 */
final class PaymentTotals {

	/**
	 * Total in minor units for the form's payment fields.
	 *
	 * @param array<int, array<string, mixed>> $definitions Form field definitions.
	 * @param array<string, mixed>             $rawById     Raw posted values keyed by field id.
	 */
	public static function totalMinor( array $definitions, array $rawById ): int {
		$total = 0;

		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$type = FieldTypeRegistry::get( (string) ( $definition['type'] ?? '' ) );

			if ( ! $type instanceof PaymentField ) {
				continue;
			}

			$fieldId = (string) ( $definition['id'] ?? '' );

			$total += $type->contributionMinor( $definition, $rawById[ $fieldId ] ?? null );
		}

		return max( 0, $total );
	}

	/**
	 * Full payment breakdown for one submission: currency, server-computed
	 * total and the human-readable line items stored in the `_payment` meta
	 * and sent to the checkout service.
	 *
	 * @param array<int, array<string, mixed>> $definitions Form field definitions.
	 * @param array<string, mixed>             $rawById     Raw posted values keyed by field id.
	 * @param string                           $currency    Form currency.
	 * @return array{currency: string, total_minor: int, items: array<int, array{field_id: string, label: string, detail: string, amount_minor: int}>}
	 */
	public static function breakdown( array $definitions, array $rawById, string $currency ): array {
		$currency = Money::normalizeCurrency( $currency );
		$items    = [];

		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$type = FieldTypeRegistry::get( (string) ( $definition['type'] ?? '' ) );

			if ( ! $type instanceof PaymentField ) {
				continue;
			}

			$fieldId = (string) ( $definition['id'] ?? '' );
			$label   = (string) ( $definition['label'] ?? '' );

			foreach ( $type->itemsForRaw( $definition, $rawById[ $fieldId ] ?? null ) as $item ) {
				$items[] = [
					'field_id'     => $fieldId,
					'label'        => $label,
					'detail'       => $item['detail'],
					'amount_minor' => $item['amount_minor'],
				];
			}
		}

		$total = 0;

		foreach ( $items as $item ) {
			$total += $item['amount_minor'];
		}

		return [
			'currency'    => $currency,
			'total_minor' => max( 0, $total ),
			'items'       => $items,
		];
	}

	/**
	 * Whether the form contains at least one payment field.
	 *
	 * @param array<int, array<string, mixed>> $definitions Form field definitions.
	 */
	public static function hasPaymentFields( array $definitions ): bool {
		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			if ( FieldTypeRegistry::get( (string) ( $definition['type'] ?? '' ) ) instanceof PaymentField ) {
				return true;
			}
		}

		return false;
	}
}
