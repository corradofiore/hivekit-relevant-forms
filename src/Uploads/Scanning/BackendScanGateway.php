<?php
/**
 * HTTP client for the backend scanning endpoint (Stage 2).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Uploads\Scanning;

use RelevantForms\Support\Logger;
use RelevantForms\Uploads\Domain\Upload;

/**
 * The WordPress plugin as API client for backend deep scanning.
 *
 * Local-provider bytes are streamed to the backend; managed-storage
 * uploads are scanned by the backend on its own object (no bytes
 * traverse WordPress). Never exposes permanent credentials.
 */
final class BackendScanGateway implements DeepScanGatewayInterface {

	/**
	 * Constructor.
	 *
	 * @param string $baseUrl Versioned API base — origin + /api/v1
	 *                        (see CloudConfig::apiBase()).
	 * @param string $apiKey  Cloud site key.
	 */
	public function __construct(
		private readonly string $baseUrl = '',
		private readonly string $apiKey = '',
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function submit( Upload $upload, string $localPath ): ?string {
		if ( 'local' !== $upload->provider ) {
			// Remote-reference submissions were a bring-your-own-storage
			// affordance; managed uploads use submitManaged() and other
			// providers are a Pro extension point (T1).
			Logger::warning( 'Deep-scan submission skipped: unsupported provider.', [ 'provider' => $upload->provider ] );

			return null;
		}

		$response = $this->postRaw(
			$this->baseUrl . '/uploads/scan',
			$localPath,
			$upload->originalFilename,
			$upload->objectKey,
		);

		return $this->scanRequestId( $response );
	}

	/**
	 * {@inheritDoc}
	 */
	public function submitManaged( Upload $upload ): ?string {
		$response = wp_remote_post(
			$this->baseUrl . '/uploads/scan',
			[
				'timeout' => 30,
				'headers' => array_merge(
					$this->headers(),
					[ 'Content-Type' => 'application/json' ]
				),
				'body'    => wp_json_encode(
					[
						'provider'   => 'managed',
						'object_key' => $upload->objectKey,
					]
				),
			]
		);

		return $this->scanRequestId( $response );
	}

	/**
	 * Decode the scan-request id from a submission response.
	 *
	 * @param array<string,mixed>|\WP_Error $response HTTP response.
	 */
	private function scanRequestId( array|\WP_Error $response ): ?string {
		if ( is_wp_error( $response ) ) {
			Logger::warning( 'Backend scan submission failed', [ 'message' => $response->get_error_message() ] );

			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 202 !== $code && 201 !== $code ) {
			Logger::warning( 'Backend scan submission rejected', [ 'code' => $code ] );

			return null;
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$id      = is_array( $payload ) ? (string) ( $payload['data']['scan_request_id'] ?? '' ) : '';

		return '' !== $id ? $id : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch( string $externalId ): ?MalwareScanResult {
		$response = wp_remote_get(
			$this->baseUrl . '/uploads/scan/' . rawurlencode( $externalId ),
			[
				'timeout' => 30,
				'headers' => $this->headers(),
			]
		);

		if ( is_wp_error( $response ) ) {
			return null; // Transient: stay pending, poll again.
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $payload ) || ! isset( $payload['data']['status'] ) ) {
			return null;
		}

		$status = (string) $payload['data']['status'];

		if ( in_array( $status, [ 'queued', 'scanning' ], true ) ) {
			return null;
		}

		$reference = (string) ( $payload['data']['reference'] ?? '' );

		return match ( $status ) {
			'clean'     => MalwareScanResult::clean( 'backend', $reference ),
			'infected'  => MalwareScanResult::infected( 'backend', $reference ),
			'suspicious' => MalwareScanResult::suspicious( 'backend', $reference ),
			default     => MalwareScanResult::error( 'backend', (string) ( $payload['data']['message'] ?? '' ) ),
		};
	}

	/**
	 * Raw-byte submission for local-provider uploads.
	 *
	 * Bodies are bounded by the field max_size policy (default 10 MB),
	 * so a single in-memory copy is acceptable on the WordPress host.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function postRaw( string $url, string $localPath, string $filename, string $objectKey ): array|\WP_Error {
		$bytes = file_get_contents( $localPath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded by the upload policy size limit.

		if ( false === $bytes ) {
			return new \WP_Error( 'scan_stream', __( 'Could not read the local copy.', 'relevant-forms' ) );
		}

		return wp_remote_post(
			$url,
			[
				'timeout' => 120,
				'headers' => array_merge(
					$this->headers(),
					[
						'Content-Type'          => 'application/octet-stream',
						'X-Relevant-Filename'   => $filename,
						'X-Relevant-Object-Key' => $objectKey,
					]
				),
				'body'    => $bytes,
			]
		);
	}

	/**
	 * Shared authorization headers.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->apiKey,
			'Accept'        => 'application/json',
		];
	}
}
