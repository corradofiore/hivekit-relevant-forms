<?php
/**
 * Money handling for payment fields.
 *
 * All amounts are integers in minor units (cents); this class is the single
 * conversion and formatting point. Pure: no WordPress calls beyond
 * translation, fully unit-testable.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Payments;

/**
 * Minor-unit conversion, formatting and fee math.
 */
final class Money {

	/**
	 * Fallback currency when a form has none configured.
	 */
	public const DEFAULT_CURRENCY = 'usd';

	/**
	 * Currencies accepted by the v1 settings selector.
	 *
	 * @var array<int, string>
	 */
	public const SUPPORTED_CURRENCIES = [
		'usd',
		'eur',
		'gbp',
		'aud',
		'cad',
		'chf',
		'sek',
		'nok',
		'dkk',
		'pln',
		'czk',
		'huf',
		'ron',
		'inr',
		'sgd',
		'hkd',
		'nzd',
		'brl',
		'mxn',
		'jpy',
	];

	/**
	 * Zero-decimal currencies (amounts are whole units).
	 *
	 * @var array<int, string>
	 */
	private const ZERO_DECIMAL = [
		'bif',
		'clp',
		'djf',
		'gnf',
		'jpy',
		'kmf',
		'krw',
		'mga',
		'pyg',
		'rwf',
		'ugx',
		'vnd',
		'vuv',
		'xaf',
		'xof',
		'xpf',
	];

	/**
	 * Three-decimal currencies.
	 *
	 * @var array<int, string>
	 */
	private const THREE_DECIMAL = [ 'bhd', 'jod', 'kwd', 'omr', 'tnd' ];

	/**
	 * Display symbols for the supported currencies.
	 *
	 * @var array<string, string>
	 */
	private const SYMBOLS = [
		'usd' => '$',
		'eur' => '€',
		'gbp' => '£',
		'jpy' => '¥',
		'inr' => '₹',
		'brl' => 'R$',
	];

	/**
	 * Normalize a currency code to the lowercase form used everywhere.
	 *
	 * @param mixed $currency Raw currency.
	 */
	public static function normalizeCurrency( mixed $currency ): string {
		if ( ! is_scalar( $currency ) ) {
			return self::DEFAULT_CURRENCY;
		}

		$code = strtolower( trim( (string) $currency ) );

		return in_array( $code, self::SUPPORTED_CURRENCIES, true ) ? $code : self::DEFAULT_CURRENCY;
	}

	/**
	 * Number of decimal places for a currency.
	 */
	public static function decimals( string $currency ): int {
		$currency = strtolower( $currency );

		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			return 0;
		}

		if ( in_array( $currency, self::THREE_DECIMAL, true ) ) {
			return 3;
		}

		return 2;
	}

	/**
	 * Parse a decimal amount ("10", "10.50", "10,50") into minor units.
	 *
	 * Extra decimals round half-up; malformed input returns null.
	 *
	 * @param mixed  $amount   Raw amount.
	 * @param string $currency Currency code.
	 */
	public static function toMinor( mixed $amount, string $currency ): ?int {
		if ( is_int( $amount ) ) {
			$amount = (string) $amount;
		}

		if ( ! is_string( $amount ) ) {
			return null;
		}

		$amount = trim( str_replace( ' ', '', $amount ) );

		if ( ! preg_match( '/^(\d+)(?:[.,](\d+))?$/', $amount, $matches ) ) {
			return null;
		}

		$decimals = self::decimals( $currency );
		$whole    = $matches[1];
		$fraction = $matches[2] ?? '';
		$roundUp  = false;

		if ( strlen( $fraction ) > $decimals ) {
			$roundUp  = substr( $fraction, $decimals, 1 ) >= '5';
			$fraction = substr( $fraction, 0, $decimals );
		}

		$fraction = str_pad( $fraction, $decimals, '0' );

		$digits = $whole . $fraction;

		if ( strlen( ltrim( $digits, '0' ) ) > 15 ) {
			return null;
		}

		$minor = (int) $digits;

		return $roundUp ? $minor + 1 : $minor;
	}

	/**
	 * Format minor units for display ("$25.00", "25,00 €" is out of scope).
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency Currency code.
	 */
	public static function format( int $minor, string $currency ): string {
		$currency = strtolower( $currency );
		$decimals = self::decimals( $currency );
		$major    = $minor / ( 10 ** $decimals );

		$number = number_format( $major, $decimals, '.', ',' );

		$symbol = self::SYMBOLS[ $currency ] ?? '';

		if ( '' !== $symbol ) {
			return $symbol . $number;
		}

		return $number . ' ' . strtoupper( $currency );
	}

	/**
	 * Render minor units as a plain decimal string ("25.00", "2500").
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency Currency code.
	 */
	public static function toDecimal( int $minor, string $currency ): string {
		$decimals = self::decimals( $currency );
		$factor   = 10 ** $decimals;

		if ( 0 === $decimals ) {
			return (string) $minor;
		}

		$major    = intdiv( $minor, $factor );
		$fraction = $minor % $factor;

		return $major . '.' . str_pad( (string) $fraction, $decimals, '0', STR_PAD_LEFT );
	}

	/**
	 * Application fee in minor units for an amount, clamped to the amount.
	 *
	 * @param int   $amountMinor Amount in minor units.
	 * @param float $percent     Fee percentage (2 = 2%).
	 */
	public static function feeMinor( int $amountMinor, float $percent ): int {
		if ( $amountMinor <= 0 || $percent <= 0.0 ) {
			return 0;
		}

		$fee = (int) round( $amountMinor * $percent / 100.0 );

		return max( 0, min( $amountMinor, $fee ) );
	}

	/**
	 * Conservative minimum charge for a currency, in minor units.
	 *
	 * Stripe enforces the exact per-currency minimum at checkout creation;
	 * this floor catches obviously-too-small amounts earlier.
	 */
	public static function minAmountMinor( string $currency ): int {
		return match ( self::decimals( $currency ) ) {
			0       => 1,
			3       => 500,
			default => 50,
		};
	}
}
