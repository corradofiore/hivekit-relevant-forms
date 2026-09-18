<?php
/**
 * Payment: single item field (fixed amount or visitor-entered donation).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

use RelevantForms\Payments\Money;

/**
 * Fixed-amount item or a donation-style amount input.
 */
final class PaymentSingleField extends PaymentField {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'payment-single';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Payment: single item', 'relevant-forms' );
	}

	/**
	 * Whether the visitor enters the amount (donation mode).
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	private function isUserAmount( array $definition ): bool {
		return ! empty( $definition['user_amount'] );
	}

	/**
	 * Configured fixed amount in minor units (0 when unset/invalid).
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	public function amountMinor( array $definition ): int {
		return Money::toMinor( (string) ( $definition['amount'] ?? '' ), $this->currency( $definition ) ) ?? 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $definition, mixed $value ): ?string {
		if ( ! $this->isUserAmount( $definition ) ) {
			return null;
		}

		$raw = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $raw ) {
			return null;
		}

		$currency = $this->currency( $definition );
		$minor    = Money::toMinor( $raw, $currency );

		if ( null === $minor ) {
			return __( 'Enter a valid amount.', 'relevant-forms' );
		}

		$floor = max( Money::minAmountMinor( $currency ), $this->definitionAmountMinor( $definition, 'min_amount' ) );

		if ( $minor < $floor ) {
			return sprintf(
				/* translators: %s: formatted minimum amount. */
				__( 'The minimum amount is %s.', 'relevant-forms' ),
				Money::format( $floor, $currency )
			);
		}

		$ceiling = $this->definitionAmountMinor( $definition, 'max_amount' );

		if ( $ceiling > 0 && $minor > $ceiling ) {
			return sprintf(
				/* translators: %s: formatted maximum amount. */
				__( 'The maximum amount is %s.', 'relevant-forms' ),
				Money::format( $ceiling, $currency )
			);
		}

		return null;
	}

	/**
	 * Parse one of the optional min/max definition amounts.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $key        Definition key.
	 */
	private function definitionAmountMinor( array $definition, string $key ): int {
		$raw = $definition[ $key ] ?? '';

		if ( ! is_scalar( $raw ) || '' === trim( (string) $raw ) ) {
			return 0;
		}

		return Money::toMinor( (string) $raw, $this->currency( $definition ) ) ?? 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		if ( ! $this->isUserAmount( $definition ) ) {
			return null;
		}

		$raw = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $raw ) {
			return '';
		}

		$currency = $this->currency( $definition );
		$minor    = Money::toMinor( $raw, $currency );

		return null !== $minor ? Money::toDecimal( $minor, $currency ) : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		$currency = $this->currency( $definition );

		if ( ! $this->isUserAmount( $definition ) ) {
			$control = sprintf(
				'<p class="relevant-forms-payment-amount" id="%1$s" data-rf-price="%3$d">%2$s</p>',
				esc_attr( FieldMarkup::id( $definition, $formId ) ),
				esc_html( Money::format( $this->amountMinor( $definition ), $currency ) ),
				$this->amountMinor( $definition )
			);

			return FieldMarkup::wrap( $definition, $formId, $control, $error );
		}

		$control = sprintf(
			'<input type="text" inputmode="decimal" autocomplete="off" class="relevant-forms-payment-input" name="%1$s" id="%2$s" value="%3$s" data-rf-amount="1" data-rf-decimals="%5$d"%4$s>',
			esc_attr( FieldMarkup::name( $definition ) ),
			esc_attr( FieldMarkup::id( $definition, $formId ) ),
			esc_attr( is_scalar( $value ) ? (string) $value : '' ),
			! empty( $definition['required'] ) ? ' required' : '',
			Money::decimals( $currency )
		);

		return FieldMarkup::wrap( $definition, $formId, $control, $error );
	}

	/**
	 * {@inheritDoc} Contributions come from the definition, not the visitor.
	 */
	public function contributionMinor( array $definition, mixed $raw ): int {
		if ( ! $this->isUserAmount( $definition ) ) {
			return $this->amountMinor( $definition );
		}

		$value = is_scalar( $raw ) ? (string) $raw : '';

		return Money::toMinor( $value, $this->currency( $definition ) ) ?? 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function itemsForRaw( array $definition, mixed $raw ): array {
		$minor = $this->contributionMinor( $definition, $raw );

		if ( $minor <= 0 ) {
			return [];
		}

		return [
			[
				'detail'       => $this->isUserAmount( $definition ) ? Money::format( $minor, $this->currency( $definition ) ) : '',
				'amount_minor' => $minor,
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return parent::defaults() + [
			'amount'      => '0.00',
			'user_amount' => false,
			'min_amount'  => '',
			'max_amount'  => '',
		];
	}
}
