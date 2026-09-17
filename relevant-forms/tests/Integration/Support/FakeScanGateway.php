<?php
/**
 * Fake deep-scan gateway for integration tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration\Support;

use RelevantForms\Uploads\Domain\Upload;
use RelevantForms\Uploads\Scanning\DeepScanGatewayInterface;
use RelevantForms\Uploads\Scanning\MalwareScanResult;

/**
 * Deterministic gateway with scripted submit/fetch behavior.
 */
final class FakeScanGateway implements DeepScanGatewayInterface {

	/**
	 * Next submit result (external id or null).
	 *
	 * @var string|null
	 */
	public ?string $next_submit = 'req-test-1';

	/**
	 * Next fetch result (null = still pending).
	 *
	 * @var MalwareScanResult|null
	 */
	public ?MalwareScanResult $next_fetch = null;

	/**
	 * Last submitted upload.
	 *
	 * @var Upload|null
	 */
	public ?Upload $last_submitted = null;

	/**
	 * {@inheritDoc}
	 */
	public function submit( Upload $upload, string $localPath ): ?string {
		$this->last_submitted = $upload;

		return $this->next_submit;
	}

	/**
	 * {@inheritDoc}
	 */
	public function submitManaged( Upload $upload ): ?string {
		$this->last_submitted = $upload;

		return $this->next_submit;
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch( string $externalId ): ?MalwareScanResult {
		return $this->next_fetch;
	}
}
