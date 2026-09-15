<?php
/**
 * Secure file upload field (doc §7.1).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Forms\Fields;

/**
 * File field with policy constraints; uploads go through the authorized
 * private storage pipeline, never the public media library.
 */
final class FileField implements FieldType {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'file';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'File upload', 'sentinel-forms' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Upload tokens are validated during the submission flow.
	 */
	public function validate( array $definition, mixed $value ): ?string {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		if ( mb_strlen( $value ) > 4096 ) {
			return __( 'Upload token list is too long.', 'sentinel-forms' );
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		return trim( (string) $value );
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		$control = sprintf(
			'<input type="file" class="sentinel-forms-file" data-field-id="%1$s" name="sentinel_upload_%1$s" id="%2$s"%3$s%4$s>'
			. '<input type="hidden" name="sentinel_upload_%1$s_ids" id="%2$s-ids" value="%5$s">'
			. '<p class="sentinel-forms-help" id="%2$s-status"></p>',
			esc_attr( (string) ( $definition['id'] ?? '' ) ),
			esc_attr( FieldMarkup::id( $definition, $formId ) ),
			! empty( $definition['required'] ) ? ' required' : '',
			$this->multipleAttribute( $definition ),
			esc_attr( (string) $value ),
		);

		return FieldMarkup::wrap( $definition, $formId, $control, $error );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return [
			'required'      => false,
			'help_text'     => '',
			'upload_policy' => [
				'allowed_ext' => \SentinelForms\Uploads\Policy\UploadPolicy::DEFAULT_EXTENSIONS,
				'max_size'    => \SentinelForms\Uploads\Policy\UploadPolicy::DEFAULT_MAX_SIZE,
				'max_files'   => \SentinelForms\Uploads\Policy\UploadPolicy::DEFAULT_MAX_FILES,
				'scan'        => true,
				'scan_level'  => \SentinelForms\Uploads\Policy\UploadPolicy::SCAN_LEVEL_BASIC,
			],
		];
	}

	/**
	 * The multiple attribute when the policy allows more than one file.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	private function multipleAttribute( array $definition ): string {
		$policy   = $definition['upload_policy'] ?? [];
		$maxFiles = absint( is_array( $policy ) ? ( $policy['max_files'] ?? 1 ) : 1 );

		return $maxFiles > 1 ? ' multiple' : '';
	}
}
