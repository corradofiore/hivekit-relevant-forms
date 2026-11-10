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
		$outcome = $this->classifyOutcome( $context );

		return $outcome['ok'] ? $outcome['data'] : null;
	}

	/**
	 * POST /v1/classifications, reporting whether the failure is worth
	 * repeating. A paid classification is never dropped because the
	 * provider (or the hop to it) hiccupped: the caller queues the request
	 * and replays it instead.
	 *
	 * @param array<string, mixed> $context Redacted classification context.
	 * @return array{ok: bool, retryable: bool, code: int, error: string, data: array<string, mixed>|null}
	 */
	public function classifyOutcome( array $context ): array {
		$response = wp_remote_post(
			$this->baseUrl . '/classifications',
			[
				'timeout' => 20,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( [ 'context' => $context ] ),
			]
		);

		return $this->outcome( $response, [ 200 ] );
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
	 * The bytes are read from the local temp file and posted with the
	 * WordPress HTTP API, which writes them to the primary and secondary
	 * buckets and verifies the primary copy. No storage credentials or
	 * presigned URLs exist on the WordPress side. A filter
	 * (`relevant_forms_cloud_stream_transport`) can replace the transport for
	 * tests or custom site setups.
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

		$size = is_file( $filePath ) ? (int) filesize( $filePath ) : 0;

		if ( $size <= 0 ) {
			return $this->transportError( 'empty_file', 'The upload payload is empty or unreadable.' );
		}

		// Uploads are bounded by the field's upload policy (10 MB by default), so
		// reading the temp file into memory keeps the relay within the HTTP API,
		// which has no streaming request body. The API writes both bucket copies
		// and verifies the primary one.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file bounded by the upload policy.
		$bytes = file_get_contents( $filePath );

		if ( false === $bytes ) {
			return $this->transportError( 'unreadable_file', 'The upload payload could not be read.' );
		}

		$response = wp_remote_post(
			$this->baseUrl . '/uploads',
			[
				'timeout' => 300,
				'headers' => [
					'Authorization'            => 'Bearer ' . $this->apiKey,
					'Accept'                   => 'application/json',
					'Content-Type'             => 'application/octet-stream',
					'X-Relevant-Object-Key'    => $objectKey,
					'X-Relevant-Filename'      => $filename,
					'X-Relevant-Declared-Mime' => $declaredMime,
				],
				'body'    => $bytes,
			]
		);

		return $this->envelope( $response );
	}

	/**
	 * GET /v1/uploads/{key}/content — stream a managed object into a file.
	 *
	 * The WordPress HTTP API streams the response straight to the sink file, so
	 * the managed object is never buffered in PHP memory.
	 *
	 * @param string $objectKey Client object key.
	 * @param string $sinkPath  Destination file path.
	 */
	public function downloadManagedContent( string $objectKey, string $sinkPath ): bool {
		$override = apply_filters( 'relevant_forms_cloud_stream_transport', null, 'download', $objectKey, $sinkPath );

		if ( is_bool( $override ) ) {
			return $override;
		}

		$encodedKey = implode( '/', array_map( 'rawurlencode', explode( '/', $objectKey ) ) );

		$response = wp_remote_get(
			$this->baseUrl . '/uploads/' . $encodedKey . '/content',
			[
				'timeout'  => 300,
				'headers'  => [
					'Authorization' => 'Bearer ' . $this->apiKey,
					'Accept'        => 'application/octet-stream',
				],
				'stream'   => true,
				'filename' => $sinkPath,
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- discard a failed partial download.
			@unlink( $sinkPath );

			return false;
		}

		return true;
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
	 * Normalize a response, keeping enough detail to decide whether
	 * repeating the request could succeed.
	 *
	 * @param array<string, mixed>|\WP_Error $response HTTP response or transport error.
	 * @param array<int, int>                $accepted Accepted status codes.
	 * @return array{ok: bool, retryable: bool, code: int, error: string, data: array<string, mixed>|null}
	 */
	private function outcome( array|\WP_Error $response, array $accepted ): array {
		if ( is_wp_error( $response ) ) {
			$error = (string) $response->get_error_message();
			Logger::warning( 'Cloud API request failed', [ 'message' => $error ] );

			return [
				'ok'        => false,
				'retryable' => true,
				'code'      => 0,
				'error'     => $error,
				'data'      => null,
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( ! in_array( $code, $accepted, true ) ) {
			$error = $this->errorCode( (string) wp_remote_retrieve_body( $response ) );

			Logger::warning(
				'Cloud API request rejected',
				[
					'code'  => $code,
					'error' => $error,
				]
			);

			return [
				'ok'        => false,
				'retryable' => self::isRetryable( $code ),
				'code'      => $code,
				'error'     => '' !== $error ? $error : 'http_' . $code,
				'data'      => null,
			];
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $payload ) || ! is_array( $payload['data'] ?? null ) ) {
			// A success we cannot read is worth another attempt, not a drop.
			return [
				'ok'        => false,
				'retryable' => true,
				'code'      => $code,
				'error'     => 'unreadable_response',
				'data'      => null,
			];
		}

		return [
			'ok'        => true,
			'retryable' => false,
			'code'      => $code,
			'error'     => '',
			'data'      => $payload['data'],
		];
	}

	/**
	 * Whether repeating a request that failed this way could succeed.
	 * Entitlement and credential answers are final; the rest is transient.
	 *
	 * @param int $code HTTP status.
	 */
	private static function isRetryable( int $code ): bool {
		return $code >= 500 || 429 === $code || 408 === $code;
	}

	/**
	 * Machine-readable error code from an error body, when it carries one.
	 *
	 * @param string $body Response body.
	 */
	private function errorCode( string $body ): string {
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$code = $decoded['error']['code'] ?? null;

		return is_string( $code ) ? $code : '';
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
