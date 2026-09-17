<?php
/**
 * Upload validation (doc §35): never trust filename, extension or headers.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Uploads\Validation;

use RelevantForms\Uploads\Policy\UploadPolicy;

/**
 * Validates declared metadata and (when bytes are available) file signatures.
 */
final class FileValidator {

	/**
	 * Validate declared metadata against the policy.
	 *
	 * @param UploadPolicy $policy   Field policy.
	 * @param string       $filename Client filename.
	 * @param int          $size     Declared size in bytes.
	 */
	public function validateDeclared( UploadPolicy $policy, string $filename, int $size ): ?string {
		if ( $size > $policy->maxSize ) {
			return __( 'File is too large.', 'relevant-forms' );
		}

		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( '' === $extension || ! in_array( $extension, $policy->extensions, true ) ) {
			return __( 'File type not allowed.', 'relevant-forms' );
		}

		return null;
	}

	/**
	 * Detect the real MIME type from file bytes (magic bytes, not filename).
	 *
	 * @param string $filePath Local file path.
	 */
	public function detectMime( string $filePath ): string {
		if ( ! is_file( $filePath ) || ! function_exists( 'finfo_open' ) ) {
			return '';
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );

		if ( false === $finfo ) {
			return '';
		}

		// No finfo_close(): with PHP 8.5 the call is deprecated because the
		// finfo object is freed automatically.
		return (string) finfo_file( $finfo, $filePath );
	}

	/**
	 * Sanitize a client filename for display storage (never used in keys).
	 */
	public function sanitizeFilename( string $filename ): string {
		$filename = wp_basename( (string) $filename );
		$filename = sanitize_file_name( $filename );

		return '' !== $filename ? $filename : 'file';
	}
}
