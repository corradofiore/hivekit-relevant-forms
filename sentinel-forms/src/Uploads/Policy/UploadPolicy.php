<?php
/**
 * Upload policy per file field (doc §8, §35).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Policy;

/**
 * Field-level constraints for file uploads.
 */
final class UploadPolicy {

	/**
	 * Default allowed extensions.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip' ];

	/**
	 * Default max size (10 MB).
	 */
	public const DEFAULT_MAX_SIZE = 10485760;

	/**
	 * Default max files per submission.
	 */
	public const DEFAULT_MAX_FILES = 5;

	/**
	 * Policy data.
	 *
	 * @param array<int,string> $extensions Allowed extensions.
	 * @param int               $maxSize    Per-file max bytes.
	 * @param int               $maxFiles   Max files per submission.
	 */
	private function __construct(
		public readonly array $extensions,
		public readonly int $maxSize,
		public readonly int $maxFiles,
	) {}

	/**
	 * Build a policy from a field definition, with the filter applied.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	public static function fromDefinition( array $definition ): self {
		$raw      = $definition['upload_policy'] ?? [];
		$raw      = is_array( $raw ) ? $raw : [];
		$maxSize  = absint( $raw['max_size'] ?? self::DEFAULT_MAX_SIZE );
		$maxFiles = absint( $raw['max_files'] ?? self::DEFAULT_MAX_FILES );
		$policy   = new self(
			is_array( $raw['allowed_ext'] ?? null ) ? array_map( 'strtolower', $raw['allowed_ext'] ) : self::DEFAULT_EXTENSIONS,
			0 !== $maxSize ? $maxSize : self::DEFAULT_MAX_SIZE,
			0 !== $maxFiles ? $maxFiles : self::DEFAULT_MAX_FILES,
		);

		$filtered = apply_filters( 'sentinel_forms_upload_policy', $policy, $definition );

		return $filtered instanceof self ? $filtered : $policy;
	}
}
