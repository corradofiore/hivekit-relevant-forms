<?php
/**
 * HTTP client for the Relevant Cloud service API (v2 §9).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Cloud;

use RelevantForms\Support\Logger;

/**
 * Thin API client. Returns decoded data arrays or null on failure;
 * never throws across the WordPress request boundary.
 */
final class CloudClient {

	/**
	 * Constructor.
	 *
	 * @param string $baseUrl Versioned API base — origin + /api/v1
	 *                        (see CloudConfig::apiBase()).
	 * @param string $apiKey  Site-scoped bearer key.
	 */
	public function __construct(
		private readonly string $baseUrl = '',
		private readonly string $apiKey = '',
	) {}

	/**
	 * GET /v1/health — service reachability probe for the diagnostics
	 * screen (no site key required).
	 *
	 * @return array{status: int, data: array<string, mixed>|null, error: array<string, mixed>|null}
	 */
	public function health(): array {
		return $this->envelope(
			wp_remote_get(
				$this->baseUrl . '/health',
				[
					'timeout' => 10,
					'headers' => $this->headers(),
				]
			)
		);
	}

	/**
	 * POST /v1/sites/connect — bind this site key to a site record.
	 *
	 * @return array<string, mixed>|null
	 */
	public function connect( string $siteUrl, string $siteName = '' ): ?array {
		$response = wp_remote_post(
			$this->baseUrl . '/sites/connect',
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
			$this->baseUrl . '/sites/' . rawurlencode( $siteId ) . '/capabilities',
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
			$this->baseUrl . '/usage',
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
			$this->baseUrl . '/classifications',
			[
				'timeout' => 20,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( [ 'context' => $context ] ),
			]
		);

		return $this->decode( $response, [ 200 ] );
	}

	/**
	 * POST /v1/alerts/sms — queue one managed priority SMS (Phase 5).
	 *
	 * @param array<string, mixed> $payload Recipient + minimal alert payload.
	 * @return array<string, mixed>|null
	 */
	public function sendSmsAlert( array $payload ): ?array {
		$response = wp_remote_post(
			$this->baseUrl . '/alerts/sms',
			[
				'timeout' => 15,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $payload ),
			]
		);

		return $this->decode( $response, [ 200, 202 ] );
	}

	/**
	 * POST /v1/sms/verifications — issue a 6-digit code to a number.
	 *
	 * @param string $phone Normalized E.164 number.
	 * @return array{status: int, data: array<string, mixed>|null, error: array<string, mixed>|null}
	 */
	public function requestSmsVerification( string $phone ): array {
		return $this->postEnvelope( '/sms/verifications', [ 'phone' => $phone ], 15 );
	}

	/**
	 * POST /v1/sms/verifications/verify — redeem the 6-digit code.
	 *
	 * @param int    $verificationId Verification id from the send step.
	 * @param string $code           6-digit code.
	 * @return array{status: int, data: array<string, mixed>|null, error: array<string, mixed>|null}
	 */
	public function verifySmsCode( int $verificationId, string $code ): array {
		return $this->postEnvelope(
			'/sms/verifications/verify',
			[
				'verification_id' => $verificationId,
				'code'            => $code,
			],
			15
		);
	}

	/**
	 * POST /v1/payments/sessions — create a hosted Checkout Session on the
	 * owner's connected Stripe account (payments doc §5).
	 *
	 * @param array<string, mixed> $payload Form, entry, line items, currency and return URLs.
	 * @return array<string, mixed>|null
	 */
	public function createPaymentSession( array $payload ): ?array {
		$response = wp_remote_post(
			$this->baseUrl . '/payments/sessions',
			[
				'timeout' => 30,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $payload ),
			]
		);

		return $this->decode( $response, [ 200, 201 ] );
	}

	/**
	 * GET /v1/payments/sessions/{id} — session status poll (return page,
	 * no-JS path).
	 *
	 * @param string $sessionId Checkout session reference.
	 * @return array<string, mixed>|null
	 */
	public function paymentSession( string $sessionId ): ?array {
		$response = wp_remote_get(
			$this->baseUrl . '/payments/sessions/' . rawurlencode( $sessionId ),
			[
				'timeout' => 15,
				'headers' => $this->headers(),
			]
		);

		return $this->decode( $response, [ 200 ] );
	}

	/**
	 * POST /v1/payments/{id}/refund — full refund (v1).
	 *
	 * @param string $sessionId Checkout session reference.
	 * @return array<string, mixed>|null
	 */
	public function refundPayment( string $sessionId ): ?array {
		$response = wp_remote_post(
			$this->baseUrl . '/payments/' . rawurlencode( $sessionId ) . '/refund',
			[
				'timeout' => 30,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( [] ),
			]
		);

		return $this->decode( $response, [ 200, 201 ] );
	}

	/**
	 * POST /v1/payments/connect — start the Stripe Connect OAuth flow.
	 *
	 * @return array<string, mixed>|null
	 */
	public function paymentsConnect(): ?array {
		$response = wp_remote_post(
			$this->baseUrl . '/payments/connect',
			[
				'timeout' => 20,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( [] ),
			]
		);

		return $this->decode( $response, [ 200, 201 ] );
	}

	/**
	 * GET /v1/payments/connect/status — connection state snapshot.
	 *
	 * @return array<string, mixed>|null
	 */
	public function paymentsStatus(): ?array {
		$response = wp_remote_get(
			$this->baseUrl . '/payments/connect/status',
			[
				'timeout' => 15,
				'headers' => $this->headers(),
			]
		);

		return $this->decode( $response, [ 200 ] );
	}

	/**
	 * POST /v1/payments/connect/disconnect — revoke the connection.
	 *
	 * @return array<string, mixed>|null
	 */
	public function paymentsDisconnect(): ?array {
		$response = wp_remote_post(
			$this->baseUrl . '/payments/connect/disconnect',
			[
				'timeout' => 20,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( [] ),
			]
		);

		return $this->decode( $response, [ 200 ] );
	}

	/**
	 * POST /v1/uploads — relay one managed file to the API.
	 *
	 * The bytes stream straight from the local file to the API (cURL), which
	 * writes them to the primary and secondary buckets and verifies the
	 * primary copy. No storage credentials or presigned URLs exist on the
	 * WordPress side. A filter (`relevant_forms_cloud_stream_transport`) can
	 * replace the cURL transport for tests or custom site setups.
	 *
	 * @param string $objectKey    Client object key.
	 * @param string $filename     Original filename.
	 * @param string $declaredMime Declared MIME type.
	 * @param string $filePath     Local temp file with the bytes.
	 * @return array{status: int, data: array<string, mixed>|null, error: array<string, mixed>|null}
	 */
	public function uploadManagedFile( string $objectKey, string $filename, string $declaredMime, string $filePath ): array {
		$override = apply_filters( 'relevant_forms_cloud_stream_transport', null, 'upload', $objectKey, $filePath );

		if ( is_array( $override ) ) {
			return $override + [
				'status' => 0,
				'data'   => null,
				'error'  => null,
			];
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return $this->transportError( 'curl_required', 'The cURL extension is required to relay managed uploads.' );
		}

		$size = is_file( $filePath ) ? (int) filesize( $filePath ) : 0;

		if ( $size <= 0 ) {
			return $this->transportError( 'empty_file', 'The upload payload is empty or unreadable.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming relay of a temp file.
		$handle = fopen( $filePath, 'rb' );

		if ( false === $handle ) {
			return $this->transportError( 'unreadable_file', 'The upload payload could not be read.' );
		}

		// cURL streams the file handle straight from disk; wp_remote_* would load
		// the whole upload into PHP memory.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- streaming relay, not a buffered request.
		$request = curl_init( $this->baseUrl . '/uploads' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- cURL streaming options (CURLOPT_UPLOAD/INFILE).
		curl_setopt_array(
			$request,
			[
				CURLOPT_UPLOAD         => true,
				CURLOPT_INFILE         => $handle,
				CURLOPT_INFILESIZE     => $size,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_TIMEOUT        => 300,
				CURLOPT_HTTPHEADER     => [
					'Authorization: Bearer ' . $this->apiKey,
					'Accept: application/json',
					'Content-Type: application/octet-stream',
					'Expect:',
					'X-Relevant-Object-Key: ' . $objectKey,
					'X-Relevant-Filename: ' . $filename,
					'X-Relevant-Declared-Mime: ' . $declaredMime,
				],
			]
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- streaming relay, not a buffered request.
		$body = curl_exec( $request );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- response code of the streaming relay.
		$code = (int) curl_getinfo( $request, CURLINFO_RESPONSE_CODE );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error -- transport error of the streaming relay.
		$error = curl_error( $request );

		unset( $request );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the relay stream.
		fclose( $handle );

		if ( false === $body ) {
			Logger::warning( 'Managed upload relay failed', [ 'message' => $error ] );

			return $this->transportError( 'network_error', $error );
		}

		$decoded = json_decode( (string) $body, true );
		$decoded = is_array( $decoded ) ? $decoded : [];

		return [
			'status' => $code,
			'data'   => is_array( $decoded['data'] ?? null ) ? $decoded['data'] : null,
			'error'  => is_array( $decoded['error'] ?? null ) ? $decoded['error'] : null,
		];
	}

	/**
	 * GET /v1/uploads/{key}/content — stream a managed object into a file.
	 *
	 * @param string $objectKey Client object key.
	 * @param string $sinkPath  Destination file path.
	 */
	public function downloadManagedContent( string $objectKey, string $sinkPath ): bool {
		$override = apply_filters( 'relevant_forms_cloud_stream_transport', null, 'download', $objectKey, $sinkPath );

		if ( is_bool( $override ) ) {
			return $override;
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming download sink.
		$sink = fopen( $sinkPath, 'wb' );

		if ( false === $sink ) {
			return false;
		}

		$encodedKey = implode( '/', array_map( 'rawurlencode', explode( '/', $objectKey ) ) );

		// cURL streams the response straight to disk; wp_remote_* would buffer
		// the whole download in PHP memory.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- streaming download, not a buffered request.
		$request = curl_init( $this->baseUrl . '/uploads/' . $encodedKey . '/content' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- cURL streaming options (CURLOPT_FILE).
		curl_setopt_array(
			$request,
			[
				CURLOPT_FILE           => $sink,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_TIMEOUT        => 300,
				CURLOPT_FAILONERROR    => true,
				CURLOPT_HTTPHEADER     => [
					'Authorization: Bearer ' . $this->apiKey,
					'Accept: application/octet-stream',
				],
			]
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- streaming download, not a buffered request.
		$ok = curl_exec( $request );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- response code of the streaming download.
		$code = (int) curl_getinfo( $request, CURLINFO_RESPONSE_CODE );

		unset( $request );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the download sink.
		fclose( $sink );

		if ( false === $ok ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- discard a failed partial download.
			@unlink( $sinkPath );

			return false;
		}

		return $code >= 200 && $code < 300;
	}

	/**
	 * Error envelope for transport-level failures.
	 *
	 * @return array{status: int, data: null, error: array<string, mixed>}
	 */
	private function transportError( string $code, string $message ): array {
		return [
			'status' => 0,
			'data'   => null,
			'error'  => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}

	/**
	 * GET /v1/uploads/status — managed upload scan state.
	 *
	 * @param string $objectKey Client object key.
	 * @return array<string, mixed>|null
	 */
	public function managedUploadStatus( string $objectKey ): ?array {
		$response = wp_remote_get(
			$this->baseUrl . '/uploads/status?object_key=' . rawurlencode( $objectKey ),
			[
				'timeout' => 15,
				'headers' => $this->headers(),
			]
		);

		return $this->decode( $response, [ 200 ] );
	}

	/**
	 * DELETE /v1/uploads — remove a managed object.
	 *
	 * @param string $objectKey Client object key.
	 */
	public function deleteManagedUpload( string $objectKey ): bool {
		$response = wp_remote_request(
			$this->baseUrl . '/uploads?object_key=' . rawurlencode( $objectKey ),
			[
				'method'  => 'DELETE',
				'timeout' => 15,
				'headers' => $this->headers(),
			]
		);

		return null !== $this->decode( $response, [ 200 ] );
	}

	/**
	 * GET /v1/billing/status — subscription snapshot (Phase 8).
	 *
	 * @return array<string, mixed>|null
	 */
	public function billingStatus(): ?array {
		$response = wp_remote_get(
			$this->baseUrl . '/billing/status',
			[
				'timeout' => 15,
				'headers' => $this->headers(),
			]
		);

		return $this->decode( $response, [ 200 ] );
	}

	/**
	 * POST /v1/billing/checkout — hosted checkout session for a plan.
	 *
	 * @return array<string, mixed>|null
	 */
	public function billingCheckout( string $plan, string $successUrl, string $cancelUrl ): ?array {
		$response = wp_remote_post(
			$this->baseUrl . '/billing/checkout',
			[
				'timeout' => 20,
				'headers' => $this->headers(),
				'body'    => wp_json_encode(
					[
						'plan'        => $plan,
						'success_url' => $successUrl,
						'cancel_url'  => $cancelUrl,
					]
				),
			]
		);

		return $this->decode( $response, [ 200 ] );
	}

	/**
	 * GET /v1/billing/portal — hosted customer portal (invoices, cards).
	 *
	 * @return array<string, mixed>|null
	 */
	public function billingPortal( string $returnUrl ): ?array {
		$response = wp_remote_get(
			$this->baseUrl . '/billing/portal?return_url=' . rawurlencode( $returnUrl ),
			[
				'timeout' => 15,
				'headers' => $this->headers(),
			]
		);

		return $this->decode( $response, [ 200 ] );
	}

	/**
	 * POST with the raw response envelope (status + data/error), so callers
	 * can surface API error codes (invalid_code, rate_limited, ...) to the
	 * form editor instead of a generic failure.
	 *
	 * @param string               $path    Absolute API path.
	 * @param array<string, mixed> $payload JSON body.
	 * @param int                  $timeout Timeout in seconds.
	 * @return array{status: int, data: array<string, mixed>|null, error: array<string, mixed>|null}
	 */
	private function postEnvelope( string $path, array $payload, int $timeout ): array {
		return $this->envelope(
			wp_remote_post(
				$this->baseUrl . $path,
				[
					'timeout' => $timeout,
					'headers' => $this->headers(),
					'body'    => wp_json_encode( $payload ),
				]
			)
		);
	}

	/**
	 * Normalize an HTTP response into the raw {status, data, error}
	 * envelope, logging network failures without throwing.
	 *
	 * @param array<string, mixed>|\WP_Error $response HTTP response.
	 * @return array{status: int, data: array<string, mixed>|null, error: array<string, mixed>|null}
	 */
	private function envelope( array|\WP_Error $response ): array {
		if ( is_wp_error( $response ) ) {
			Logger::warning( 'Cloud API request failed', [ 'message' => $response->get_error_message() ] );

			return [
				'status' => 0,
				'data'   => null,
				'error'  => [
					'code'    => 'network_error',
					'message' => $response->get_error_message(),
				],
			];
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$body   = is_array( $body ) ? $body : [];

		return [
			'status' => $status,
			'data'   => is_array( $body['data'] ?? null ) ? $body['data'] : null,
			'error'  => is_array( $body['error'] ?? null ) ? $body['error'] : null,
		];
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
