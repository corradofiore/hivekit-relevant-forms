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
	 * POST /v1/alerts/sms — queue one managed priority SMS (Phase 5).
	 *
	 * @param array<string, mixed> $payload Recipient + minimal alert payload.
	 * @return array<string, mixed>|null
	 */
	public function sendSmsAlert( array $payload ): ?array {
		$response = wp_remote_post(
			$this->baseUrl . '/api/v1/alerts/sms',
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
		return $this->postEnvelope( '/api/v1/sms/verifications', [ 'phone' => $phone ], 15 );
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
			'/api/v1/sms/verifications/verify',
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
			$this->baseUrl . '/api/v1/payments/sessions',
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
			$this->baseUrl . '/api/v1/payments/sessions/' . rawurlencode( $sessionId ),
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
			$this->baseUrl . '/api/v1/payments/' . rawurlencode( $sessionId ) . '/refund',
			[
				'timeout' => 30,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( [] ),
			]
		);

		return $this->decode( $response, [ 200, 201 ] );
	}

	/**
	 * POST /v1/uploads/authorize — managed storage upload authorization.
	 *
	 * @param array<string, mixed> $payload Object key, filename, MIME, size.
	 * @return array<string, mixed>|null
	 */
	public function authorizeManagedUpload( array $payload ): ?array {
		$response = wp_remote_post(
			$this->baseUrl . '/api/v1/uploads/authorize',
			[
				'timeout' => 20,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $payload ),
			]
		);

		return $this->decode( $response, [ 200, 201 ] );
	}

	/**
	 * POST /v1/uploads/complete — verify a managed upload landed.
	 *
	 * @param string $objectKey Client object key.
	 * @return array<string, mixed>|null
	 */
	public function completeManagedUpload( string $objectKey ): ?array {
		$response = wp_remote_post(
			$this->baseUrl . '/api/v1/uploads/complete',
			[
				'timeout' => 20,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( [ 'object_key' => $objectKey ] ),
			]
		);

		return $this->decode( $response, [ 200 ] );
	}

	/**
	 * GET /v1/uploads/status — managed upload scan state.
	 *
	 * @param string $objectKey Client object key.
	 * @return array<string, mixed>|null
	 */
	public function managedUploadStatus( string $objectKey ): ?array {
		$response = wp_remote_get(
			$this->baseUrl . '/api/v1/uploads/status?object_key=' . rawurlencode( $objectKey ),
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
			$this->baseUrl . '/api/v1/uploads?object_key=' . rawurlencode( $objectKey ),
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
			$this->baseUrl . '/api/v1/billing/status',
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
			$this->baseUrl . '/api/v1/billing/checkout',
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
			$this->baseUrl . '/api/v1/billing/portal?return_url=' . rawurlencode( $returnUrl ),
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
		$response = wp_remote_post(
			$this->baseUrl . $path,
			[
				'timeout' => $timeout,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $payload ),
			]
		);

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
