<?php
/**
 * Deep-scan gateway contract (Stage 2 backend).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Scanning;

use SentinelForms\Uploads\Domain\Upload;

/**
 * Submits objects for complex scanning and retrieves outcomes.
 * Implementations must never report CLEAN unless the backend said so.
 */
interface DeepScanGatewayInterface {

	/**
	 * Submit an upload for deep scanning.
	 *
	 * @param Upload $upload    Upload record (object key, provider).
	 * @param string $localPath Local copy for streaming (local provider).
	 * @return string|null External request id, or null on failure.
	 */
	public function submit( Upload $upload, string $localPath ): ?string;

	/**
	 * Fetch the outcome for a submitted request.
	 *
	 * @return MalwareScanResult|null Result, or null while still pending.
	 */
	public function fetch( string $externalId ): ?MalwareScanResult;
}
