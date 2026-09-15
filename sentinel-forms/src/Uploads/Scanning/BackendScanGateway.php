<?php
/**
 * HTTP client for the backend scanning endpoint (Stage 2).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Scanning;

use SentinelForms\Support\Logger;
use SentinelForms\Uploads\Contracts\StoredObject;
use SentinelForms\Uploads\Domain\Upload;
use SentinelForms\Uploads\Storage\S3CompatibleProvider;

/**
 * The WordPress plugin as API client for backend deep scanning.
 *
 * Local-provider bytes are streamed as multipart; object-storage
 * uploads are referenced by a short-lived signed URL so bytes never
 * traverse WordPress. Never exposes permanent credentials.
 */
final class BackendScanGateway implements DeepScanGatewayInterface {

	/**
	 * Constructor.
	 *
	 * @param string $baseUrl Backend base URL.
	 * @param string $apiKey  Bearer API key.
	 */
	public function __construct(
		private readonly string $baseUrl = '',
		private readonly string $apiKey = '',
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function submit( Upload $upload, string $localPath ): ?string {
		$url = $this->baseUrl . '/api/v1/uploads/scan';

		if ( 'local' === $upload->provider ) {
			$response = $this->postRaw( $url, $localPath, $upload->originalFilename, $upload->objectKey );
		} else {
			$response = $this->postRemoteReference( $url, $upload );
		}

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
			$this->baseUrl . '/api/v1/uploads/scan/' . rawurlencode( $externalId ),
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
			return new \WP_Error( 'scan_stream', __( 'Could not read the local copy.', 'sentinel-forms' ) );
		}

		return wp_remote_post(
			$url,
			[
				'timeout' => 120,
				'headers' => array_merge(
					$this->headers(),
					[
						'Content-Type'          => 'application/octet-stream',
						'X-Sentinel-Filename'   => $filename,
						'X-Sentinel-Object-Key' => $objectKey,
					]
				),
				'body'    => $bytes,
			]
		);
	}

	/**
	 * JSON submission referencing object storage via a signed URL.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function postRemoteReference( string $url, Upload $upload ): array|\WP_Error {
		$provider      = new S3CompatibleProvider();
		$authorization = $provider->getDownloadAuthorization(
			new StoredObject( $upload->objectKey, $upload->storageLocation )
		);

		return wp_remote_post(
			$url,
			[
				'timeout' => 30,
				'headers' => array_merge(
					$this->headers(),
					[ 'Content-Type' => 'application/json' ]
				),
				'body'    => wp_json_encode(
					[
						'provider'   => 's3',
						'object_key' => $upload->objectKey,
						'url'        => $authorization->url,
					]
				),
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
