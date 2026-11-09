<?php
/**
 * Upload authorization DTO returned to the browser.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Uploads\Contracts;

/**
 * Short-lived, object-scoped authorization (doc §28, §37).
 */
final class UploadAuthorization {

	/**
	 * Constructor.
	 *
	 * @param string              $method       HTTP method (PUT for object storage, POST for local fallback).
	 * @param string              $url          Target URL.
	 * @param array<string,string> $headers     Additional headers (e.g. Content-Type).
	 * @param int                 $expiresAt    Unix timestamp.
	 * @param string              $objectKey    The object key being authorized.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $url,
		public readonly array $headers = [],
		public readonly int $expiresAt = 0,
		public readonly string $objectKey = '',
	) {}
}
