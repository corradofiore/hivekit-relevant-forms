<?php
/**
 * HTTP client for the Sentinel Cloud service API (v2 §9).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Cloud;

use SentinelForms\Support\Logger;

/**
 * Thin API client. Returns decoded data arrays or null on failure;
 * never throws across the WordPress request boundary.
 */
final class CloudClient {

	/**
	 * Constructor.
	 *
	 * @param string $baseUrl Cloud base URL.
	 * @param string $apiKey  Site-scoped bearer key.
	 */
	public function __construct(
		private readonly string $baseUrl = '',
		private readonly string $apiKey = '',
	) {}

	/**
	 * POST /v1/sites/connect — bind this site key to a site record.
	 *
	 * @return array<string, mixed>|null
	 */
	public function connect( string $siteUrl, string $siteName = '' ): ?array {
		$response = wp_remote_post(
			$this->baseUrl . '/api/v1/sites/connect',
			[
				'timeout' => 30,
				'headers' => $this->headers(),
				'body'    => wp_json_encode(
					[
						'site_url'  => $siteUrl,
						'site_name' => $siteName,
					]
				),
			]
		);

		return $this->decode( $response, [ 200, 201 ] );
	}

	/**
	 * GET /v1/sites/{id}/capabilities — deployed service flags.
	 *
	 * @return array<string, mixed>|null
	 */
	public function capabilities( string $siteId ): ?array {
		$response = wp_remote_get(
			$this->baseUrl . '/api/v1/sites/' . rawurlencode( $siteId ) . '/capabilities',
			[
				'timeout' => 30,
				'headers' => $this->headers(),
			]
		);

		return $this->decode( $response, [ 200 ] );
	}

	/**
	 * GET /v1/usage — current-period usage and allowances.
	 *
	 * @return array<string, mixed>|null
	 */
	public function usage(): ?array {
		$response = wp_remote_get(
			$this->baseUrl . '/api/v1/usage',
			[
				'timeout' => 30,
				'headers' => $this->headers(),
			]
		);

		return $this->decode( $response, [ 200 ] );
	}

	/**
	 * POST /v1/classifications — classify one submission.
	 *
	 * @param array<string, mixed> $context Sanitized classification context.
	 * @return array<string, mixed>|null
	 */
	public function classify( array $context ): ?array {
		$response = wp_remote_post(
			$this->baseUrl . '/api/v1/classifications',
			[
				'timeout' => 20,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( [ 'context' => $context ] ),
			]
		);

		return $this->decode( $response, [ 200 ] );
	}

	/**
	 * Decode an API envelope, logging failures without throwing.
	 *
	 * @param array<string, mixed>|\WP_Error $response HTTP response.
	 * @param array<int, int>                $accepted Accepted status codes.
	 * @return array<string, mixed>|null
	 */
	private function decode( array|\WP_Error $response, array $accepted ): ?array {
		if ( is_wp_error( $response ) ) {
			Logger::warning( 'Cloud API request failed', [ 'message' => $response->get_error_message() ] );

			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( ! in_array( $code, $accepted, true ) ) {
			Logger::warning( 'Cloud API request rejected', [ 'code' => $code ] );

			return null;
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $payload ) && is_array( $payload['data'] ?? null ) ? $payload['data'] : null;
	}

	/**
	 * Authorization headers.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->apiKey,
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
		];
	}
}
