<?php
/**
 * WPForms field mapping (doc §46–47).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Import;

use RelevantForms\Payments\Money;

/**
 * Translates WPForms field definitions into the Relevant Forms schema.
 * Pure: no WordPress calls, fully unit-testable.
 */
final class WpFormsFieldMapper {

	/**
	 * WPForms type → Relevant type.
	 *
	 * @var array<string, string>
	 */
	public const TYPE_MAP = [
		'text'             => 'text',
		'textarea'         => 'textarea',
		'email'            => 'email',
		'phone'            => 'tel',
		'select'           => 'select',
		'radio'            => 'radio',
		'checkbox'         => 'checkbox',
		'hidden'           => 'hidden',
		'number'           => 'number',
		'date'             => 'date',
		// The WPForms Date/Time field is `date-time`; date-only mode maps to
		// the native date field, date+time mode is handled in map().
		'date-time'        => 'date',
		'time'             => 'text',
		'url'              => 'url',
		'website'          => 'url',
		'file-upload'      => 'file',
		'file'             => 'file',
		// Compound fields are flattened to a single text input.
		'name'             => 'text',
		// Layout fields: page breaks drive the multi-page step navigation,
		// dividers become section separators.
		'pagebreak'        => 'pagebreak',
		'divider'          => 'section',
		// The WPForms entry preview becomes a native review step.
		'entry-preview'    => 'entry-preview',
		// The WPForms Content field becomes a rich-text display block.
		'content'          => 'content',
		// The WPForms HTML field becomes a raw custom-code block (stored and
		// rendered as authored; editing requires unfiltered_html).
		'html'             => 'html',
		// Payment fields (phase 5): amounts are preserved.
		'payment-single'   => 'payment-single',
		'payment-select'   => 'payment-select',
		'payment-multiple' => 'payment-multiple',
		'payment-checkbox' => 'payment-checkbox',
		'payment-total'    => 'payment-total',
	];

	/**
	 * WPForms payment types that are imported (amounts preserved).
	 * Unknown payment types (e.g. addon `payment-coupon`) stay unsupported.
	 *
	 * @var array<int, string>
	 */
	public const PAYMENT_TYPES = [ 'payment-single', 'payment-select', 'payment-multiple', 'payment-checkbox', 'payment-total' ];

	/**
	 * Types intentionally not imported (doc §47: common fields only).
	 *
	 * @var array<int, string>
	 */
	public const UNSUPPORTED_PREFIXES = [ 'signature', 'address', 'likert', 'rating', 'captcha' ];

	/**
	 * Whether a type is a gateway card widget.
	 *
	 * Stripe widgets only collect card details: the amounts live in the
	 * payment item fields and Relevant collects the card at the Stripe
	 * Checkout step after submission, so these fields are recognized as
	 * replaced instead of being reported as skipped.
	 *
	 * @param string $type Raw WPForms type.
	 */
	public static function isGatewayWidget( string $type ): bool {
		return str_starts_with( strtolower( trim( $type ) ), 'stripe-' );
	}

	/**
	 * Map one WPForms field, or null when unsupported.
	 *
	 * @param array<string, mixed> $field Raw WPForms field.
	 * @return array{definition: array<string, mixed>, notes: array<int, string>}|null
	 */
	public static function map( array $field ): ?array {
		$wpType = strtolower( (string) ( $field['type'] ?? '' ) );
		$notes  = [];

		if ( '' === $wpType ) {
			return null;
		}

		foreach ( self::UNSUPPORTED_PREFIXES as $prefix ) {
			if ( str_starts_with( $wpType, $prefix ) ) {
				return null;
			}
		}

		if ( ! isset( self::TYPE_MAP[ $wpType ] ) ) {
			return null;
		}

		$relevantType = self::TYPE_MAP[ $wpType ];
		$label        = trim( (string) ( $field['label'] ?? '' ) );

		if ( '' === $label && 'pagebreak' === $wpType ) {
			// WPForms keeps the page title in the `title` key.
			$label = trim( (string) ( $field['title'] ?? '' ) );
		}

		// Layout fields may intentionally carry no label (a bare divider, a
		// page without a title, a preview block, a content block or an
		// html/code block), so no humanized fallback for them.
		if ( '' === $label && ! in_array( $wpType, [ 'divider', 'pagebreak', 'entry-preview', 'content', 'html' ], true ) ) {
			$label = ucfirst( str_replace( '-', ' ', $wpType ) );
		}

		if ( 'name' === $wpType ) {
			$notes[] = __( 'Compound name field flattened to a single line of text.', 'relevant-forms' );
		}

		$definition = [
			'id'            => (string) ( $field['id'] ?? '' ),
			'type'          => $relevantType,
			'label'         => $label,
			'required'      => ! empty( $field['required'] ),
			'placeholder'   => (string) ( $field['placeholder'] ?? '' ),
			'help_text'     => (string) ( $field['description'] ?? '' ),
			'default_value' => (string) ( $field['default_value'] ?? $field['default'] ?? '' ),
			'choices'       => self::choices( $field ),
		];

		if ( in_array( $wpType, self::PAYMENT_TYPES, true ) ) {
			$definition = self::paymentDefinition( $wpType, $definition, $field, $notes );
		} elseif ( 'date-time' === $wpType ) {
			$mode = strtolower( trim( (string) ( $field['format'] ?? 'date' ) ) );

			if ( 'date' !== $mode ) {
				$definition['type'] = 'text';
				$notes[]            = __( 'Date & time field imported as a single line of text: a date field cannot carry the time of day.', 'relevant-forms' );
			}
		} elseif ( 'entry-preview' === $wpType ) {
			$rawNotice = (string) ( $field['preview-notice'] ?? '' );
			$notice    = trim( str_replace( [ "\r\n", "\r" ], "\n", wp_strip_all_tags( $rawNotice ) ) );
			$style     = strtolower( trim( (string) ( $field['style'] ?? '' ) ) );

			$definition['notice_enable'] = ! empty( $field['preview-notice-enable'] );
			$definition['notice']        = $notice;
			$definition['style']         = in_array( $style, [ 'basic', 'compact', 'table', 'table_compact' ], true ) ? $style : 'basic';

			if ( '' !== $rawNotice && $notice !== trim( $rawNotice ) ) {
				$notes[] = __( 'The preview notice was imported as plain text (formatting removed).', 'relevant-forms' );
			}
		} elseif ( 'content' === $wpType ) {
			// The block HTML is kept as authored; rendering sanitizes it.
			$definition['content'] = trim( (string) ( $field['content'] ?? '' ) );
		} elseif ( 'html' === $wpType ) {
			// Raw code is kept verbatim here: the importer gates storage on
			// the unfiltered_html capability (see WpFormsImporter) and the
			// front end renders it as authored.
			$code               = trim( (string) ( $field['code'] ?? '' ) );
			$definition['code'] = $code;

			if ( '' !== $code ) {
				$notes[] = __( 'Custom code block imported: it is rendered as authored — review and test it on this site.', 'relevant-forms' );
			}
		}

		if ( 'file' === $relevantType ) {
			$definition['upload_policy'] = self::uploadPolicy( $field );
			$notes[]                     = __( 'Uploads are converted to Relevant secure storage: private quarantine directory, malware scan required before any download.', 'relevant-forms' );
		}

		return [
			'definition' => $definition,
			'notes'      => $notes,
		];
	}

	/**
	 * Payment-specific definition enrichment (phase 5).
	 *
	 * WPForms stores the price as the choice VALUE, matching the Relevant
	 * `Label|Price` choice notation. Options without a valid positive price
	 * cannot be paid for and are skipped (with a migration note). The Single
	 * Item field keeps its fixed price or its user-entered (donation) mode
	 * with the minimum preserved.
	 *
	 * @param string               $wpType     WPForms payment type.
	 * @param array<string, mixed> $definition Baseline definition.
	 * @param array<string, mixed> $field      Raw WPForms field.
	 * @param array<int, string>   $notes      Note accumulator.
	 * @return array<string, mixed>
	 */
	private static function paymentDefinition( string $wpType, array $definition, array $field, array &$notes ): array {
		unset( $definition['choices'] );

		if ( 'payment-total' === $wpType ) {
			$notes[] = __( 'The total field is display-only: the amount is recomputed server-side from the imported items.', 'relevant-forms' );

			return $definition;
		}

		if ( 'payment-single' === $wpType ) {
			$format = strtolower( trim( (string) ( $field['format'] ?? 'single' ) ) );

			$definition['amount'] = trim( (string) ( $field['price'] ?? '' ) );

			if ( 'user' === $format ) {
				$definition['user_amount'] = true;
				$minimum                   = trim( (string) ( $field['min_price'] ?? '' ) );

				if ( '' !== $minimum ) {
					$definition['min_amount'] = $minimum;
				}

				$notes[] = __( 'Visitor-entered amount imported as a donation-style field (minimum price preserved).', 'relevant-forms' );
			} elseif ( 'hidden' === $format ) {
				$notes[] = __( 'Hidden single item imported as a regular item.', 'relevant-forms' );
			}

			return $definition;
		}

		$lines = [];

		foreach ( self::paymentChoices( $field ) as $choice ) {
			$lines[] = $choice['label'] . '|' . $choice['price'];
		}

		$definition['choices'] = $lines;

		$dropped = self::droppedPaymentOptions( $field );

		if ( $dropped > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of skipped payment options. */
				_n(
					'%d payment option without a valid price was skipped.',
					'%d payment options without a valid price were skipped.',
					$dropped,
					'relevant-forms'
				),
				$dropped
			);
		}

		return $definition;
	}

	/**
	 * Importable priced choices of a WPForms payment field, in order.
	 *
	 * Shared with the conditional conversion: the rendered options submit
	 * POSITIONS, so rule values resolve to the position among these choices.
	 *
	 * @param array<string, mixed> $field Raw WPForms payment field.
	 * @return array<int, array{key: string, label: string, price: string}>
	 */
	public static function paymentChoices( array $field ): array {
		$choices = $field['choices'] ?? [];

		if ( ! is_array( $choices ) ) {
			return [];
		}

		$result = [];

		foreach ( $choices as $key => $choice ) {
			if ( ! is_array( $choice ) ) {
				continue;
			}

			$label = trim( (string) ( $choice['label'] ?? '' ) );
			$price = trim( (string) ( $choice['value'] ?? '' ) );

			if ( '' === $label || '' === $price ) {
				continue;
			}

			$minor = Money::toMinor( $price, Money::DEFAULT_CURRENCY );

			if ( null === $minor || $minor <= 0 ) {
				continue;
			}

			$result[] = [
				'key'   => (string) $key,
				'label' => $label,
				'price' => $price,
			];
		}

		return $result;
	}

	/**
	 * Position (as submitted by the rendered field) of a WPForms payment
	 * choice key, or null when that option was not imported.
	 *
	 * @param string                    $key         Choice key from a conditional rule.
	 * @param array<string, mixed>|null $sourceField Raw WPForms field.
	 */
	public static function paymentPositionByKey( string $key, ?array $sourceField ): ?string {
		if ( null === $sourceField ) {
			return null;
		}

		$needle   = (int) $key;
		$position = 0;

		foreach ( self::paymentChoices( $sourceField ) as $choice ) {
			if ( (int) $choice['key'] === $needle ) {
				return (string) $position;
			}

			++$position;
		}

		return null;
	}

	/**
	 * Labeled payment options dropped for an invalid/zero price (note only).
	 *
	 * @param array<string, mixed> $field Raw WPForms payment field.
	 */
	private static function droppedPaymentOptions( array $field ): int {
		$choices = $field['choices'] ?? [];

		if ( ! is_array( $choices ) ) {
			return 0;
		}

		$dropped = 0;

		foreach ( $choices as $choice ) {
			if ( ! is_array( $choice ) ) {
				continue;
			}

			$label = trim( (string) ( $choice['label'] ?? '' ) );
			$price = trim( (string) ( $choice['value'] ?? '' ) );

			if ( '' === $label ) {
				continue;
			}

			$minor = Money::toMinor( $price, Money::DEFAULT_CURRENCY );

			if ( null === $minor || $minor <= 0 ) {
				++$dropped;
			}
		}

		return $dropped;
	}

	/**
	 * Normalize choices to label/value pairs.
	 *
	 * @param array<string, mixed> $field Raw WPForms field.
	 * @return array<int, array{label: string, value: string}>
	 */
	private static function choices( array $field ): array {
		$choices = $field['choices'] ?? [];

		if ( ! is_array( $choices ) ) {
			return [];
		}

		$result = [];

		foreach ( $choices as $choice ) {
			if ( ! is_array( $choice ) ) {
				continue;
			}

			$value = self::choiceValue( $choice );

			if ( '' === $value ) {
				continue;
			}

			$result[] = [
				'label' => trim( (string) ( $choice['label'] ?? '' ) ),
				'value' => $value,
			];
		}

		return $result;
	}

	/**
	 * Human-readable reason shown in the migration report when a type is
	 * skipped. Gateway widgets never reach this point (isGatewayWidget()
	 * recognizes them as replaced by the checkout step); addon payment types
	 * and unsupported fields get tailored reasons so payment migrations are
	 * not misread.
	 *
	 * @param string $type Raw WPForms type.
	 */
	public static function skipReason( string $type ): string {
		$type = strtolower( trim( $type ) );

		if ( 'payment-coupon' === $type ) {
			return __( 'Coupon fields are not supported: discounts cannot be applied during the Stripe checkout step.', 'relevant-forms' );
		}

		return __( 'Unsupported field type (not part of the initial importer scope).', 'relevant-forms' );
	}

	/**
	 * Normalize one raw choice to the value the rendered field submits.
	 *
	 * Returns an empty string when the choice has no usable label (those are
	 * dropped). Shared with the conditional conversion, which must resolve
	 * choice keys to the exact same submitted value.
	 *
	 * @param array<string, mixed> $choice Raw WPForms choice.
	 */
	public static function choiceValue( array $choice ): string {
		$label = trim( (string) ( $choice['label'] ?? '' ) );

		if ( '' === $label ) {
			return '';
		}

		$value = (string) ( $choice['value'] ?? $label );

		return '' !== $value ? $value : $label;
	}

	/**
	 * Secure-by-default upload policy for imported file fields (doc §47).
	 *
	 * @param array<string, mixed> $field Raw WPForms field.
	 * @return array<string, mixed>
	 */
	private static function uploadPolicy( array $field ): array {
		$extensions = self::extensionsFrom( (string) ( $field['extensions'] ?? '' ) );

		$maxBytes = (int) ( $field['max_size'] ?? 0 );

		if ( $maxBytes <= 0 ) {
			$maxBytes = 10 * 1048576; // WPForms default: 10 MB.
		}

		return [
			'allowed_ext' => $extensions,
			'max_size'    => $maxBytes,
			'max_files'   => max( 1, (int) ( $field['max_file_number'] ?? 1 ) ),
			'scan'        => true,
			'scan_level'  => 'basic',
		];
	}

	/**
	 * Parse a comma/space separated extension list.
	 *
	 * @param string $rawExtensions Raw extension list.
	 * @return array<int, string>
	 */
	private static function extensionsFrom( string $rawExtensions ): array {
		$extensions = [];
		$parts      = preg_split( '/[\s,]+/', strtolower( $rawExtensions ) );

		foreach ( is_array( $parts ) ? $parts : [] as $extension ) {
			$extension = ltrim( trim( $extension ), '.' );

			if ( '' !== $extension && preg_match( '/^[a-z0-9]{1,12}$/', $extension ) ) {
				$extensions[] = $extension;
			}
		}

		if ( [] === $extensions ) {
			$extensions = [ 'pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx' ];
		}

		return array_values( array_unique( $extensions ) );
	}
}
