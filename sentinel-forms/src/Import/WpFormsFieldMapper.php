<?php
/**
 * WPForms field mapping (doc §46–47).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Import;

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
		'text'        => 'text',
		'textarea'    => 'textarea',
		'email'       => 'email',
		'phone'       => 'tel',
		'select'      => 'select',
		'radio'       => 'radio',
		'checkbox'    => 'checkbox',
		'hidden'      => 'hidden',
		'number'      => 'number',
		'date'        => 'date',
		'time'        => 'text',
		'url'         => 'url',
		'website'     => 'url',
		'file-upload' => 'file',
		'file'        => 'file',
		// Compound fields are flattened to a single text input.
		'name'        => 'text',
	];

	/**
	 * Types intentionally not imported (doc §47: common fields only).
	 *
	 * @var array<int, string>
	 */
	public const UNSUPPORTED_PREFIXES = [ 'payment', 'signature', 'address', 'likert', 'rating', 'entry-preview', 'captcha', 'divider', 'pagebreak', 'html' ];

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

		if ( '' === $label ) {
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
