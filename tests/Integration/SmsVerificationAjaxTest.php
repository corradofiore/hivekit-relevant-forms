<?php
/**
 * SMS verification AJAX integration tests: the endpoints behind the form
 * editor's "Send code" / "Verify code" controls.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\SmsVerificationController;
use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Tests\Integration\Support\AjaxRequests;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class SmsVerificationAjaxTest extends TestCase {

	/**
	 * Invalid numbers are rejected before any Cloud call is made.
	 */
	public function testInvalidNumbersAreRejected(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$body = $this->dispatchSend( '3331234567' );

		$this->assertFalse( $body['success'] );
		$this->assertSame( 'invalid_phone', $body['data']['code'] );
	}

	/**
	 * Requesting a code is the explicit opt-in: on a connected site it
	 * enables the priority-SMS consent (no settings switch) and proceeds.
	 */
	public function testCodeRequestsEnableTheConsentOnConnectedSites(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->configureCloud();

		// Connected but without any capability consent yet (site_id keeps
		// the state stable — disableCapability() needs it too).
		update_option(
			'relevant_cloud_connection',
			[
				'state'   => 'account_connected',
				'site_id' => 'site-uuid-1',
				'enabled' => [],
			]
		);

		add_filter( 'pre_http_request', [ $this, 'fakeVerificationHttp' ], 10, 3 );

		try {
			$this->assertFalse( ( new CloudConnection() )->capabilityEnabled( CloudConnection::CAP_SMS ) );

			$body = $this->dispatchSend( '+393331234567' );

			$this->assertTrue( $body['success'] );
			$this->assertTrue( ( new CloudConnection() )->capabilityEnabled( CloudConnection::CAP_SMS ) );
		} finally {
			remove_all_filters( 'pre_http_request' );
		}
	}

	/**
	 * A disconnected site cannot request codes; no HTTP happens.
	 */
	public function testDisconnectedSitesCannotRequestCodes(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		update_option(
			'relevant_forms_settings',
			[
				'cloud_url'     => 'https://cloud.test',
				'cloud_api_key' => 'sfk_test',
			]
		);
		update_option( 'relevant_cloud_connection', [ 'state' => 'disconnected' ] );

		$requested = false;
		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( &$requested ) {
				$requested = true;

				return $preempt;
			}
		);

		try {
			$body = $this->dispatchSend( '+393331234567' );

			$this->assertFalse( $body['success'] );
			$this->assertSame( 'not_connected', $body['data']['code'] );
			$this->assertFalse( $requested, 'No Cloud request may happen without a connected site.' );
		} finally {
			remove_all_filters( 'pre_http_request' );
		}
	}

	/**
	 * Full flow: send issues a code, verify marks the number saveable.
	 */
	public function testSendAndVerifyFlow(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->configureCloud();

		add_filter( 'pre_http_request', [ $this, 'fakeVerificationHttp' ], 10, 3 );

		try {
			$body = $this->dispatchSend( '+39 333 123 4567' );

			$this->assertTrue( $body['success'] );
			$this->assertSame( '+393331234567', $body['data']['phone'] );
			$this->assertSame( '123456', $body['data']['dev_code'] );

			// The pending code is remembered for the verify step.
			$this->assertNotFalse(
				get_transient( 'relevant_forms_sms_pending_' . get_current_user_id() . '_' . md5( '+393331234567' ) )
			);

			$_POST                = [
				'phone' => '+39 333 123 4567',
				'code'  => '123456',
			];
			$_REQUEST['_wpnonce'] = wp_create_nonce( SmsVerificationController::ACTION_VERIFY );

			$body = AjaxRequests::dispatch( [ SmsVerificationController::class, 'verify' ] );

			$this->assertTrue( $body['success'] );
			$this->assertTrue( $body['data']['verified'] );
			$this->assertTrue( SmsVerificationController::isVerified( '+393331234567' ) );

			$pending = get_transient( 'relevant_forms_sms_pending_' . get_current_user_id() . '_' . md5( '+393331234567' ) );
			$this->assertFalse( $pending );
		} finally {
			remove_all_filters( 'pre_http_request' );
		}
	}

	/**
	 * A rejected code surfaces the Cloud error message to the editor.
	 */
	public function testRejectedCodesSurfaceTheCloudError(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->configureCloud();

		// Seed the pending verification directly.
		set_transient(
			'relevant_forms_sms_pending_' . get_current_user_id() . '_' . md5( '+393331234567' ),
			7,
			10 * MINUTE_IN_SECONDS
		);

		add_filter( 'pre_http_request', [ $this, 'fakeVerificationHttp' ], 10, 3 );

		try {
			$_POST                = [
				'phone' => '+393331234567',
				'code'  => '000000',
			];
			$_REQUEST['_wpnonce'] = wp_create_nonce( SmsVerificationController::ACTION_VERIFY );

			$body = AjaxRequests::dispatch( [ SmsVerificationController::class, 'verify' ] );

			$this->assertFalse( $body['success'] );
			$this->assertSame( 'invalid_code', $body['data']['code'] );
			$this->assertSame( 'The code is incorrect.', $body['data']['message'] );
			$this->assertFalse( SmsVerificationController::isVerified( '+393331234567' ) );
		} finally {
			remove_all_filters( 'pre_http_request' );
		}
	}

	/**
	 * Fake the Cloud verification endpoints.
	 *
	 * @param mixed  $preempt Existing short-circuit value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return array<string, mixed>|mixed
	 */
	public function fakeVerificationHttp( $preempt, $args, $url ) {
		$payload = json_decode( (string) ( $args['body'] ?? '{}' ), true );
		$payload = is_array( $payload ) ? $payload : [];

		if ( str_contains( (string) $url, '/sms/verifications/verify' ) ) {
			$ok = ( $payload['code'] ?? '' ) === '123456';

			return $this->httpResponse(
				$ok
					? [
						'data' => [
							'verified' => true,
							'phone'    => '+393331234567',
						],
					]
					: [
						'error' => [
							'code'    => 'invalid_code',
							'message' => 'The code is incorrect.',
						],
					],
				$ok ? 200 : 422
			);
		}

		if ( str_contains( (string) $url, '/sms/verifications' ) ) {
			return $this->httpResponse(
				[
					'data' => [
						'verification_id' => 7,
						'phone'           => '+393331234567',
						'delivered'       => false,
						'dev_code'        => '123456',
					],
				],
				201
			);
		}

		return $preempt;
	}

	/**
	 * Configure the Cloud URL/key and the SMS capability consent.
	 */
	private function configureCloud(): void {
		update_option(
			'relevant_forms_settings',
			[
				'cloud_url'     => 'https://cloud.test',
				'cloud_api_key' => 'sfk_test',
			]
		);
		update_option(
			'relevant_cloud_connection',
			[
				'state'   => 'account_connected',
				'enabled' => [ 'priority_sms' ],
			]
		);
	}

	/**
	 * POST to the send endpoint as an AJAX request.
	 *
	 * @param string $phone Phone input.
	 * @return array<string, mixed> Decoded response body.
	 */
	private function dispatchSend( string $phone ): array {
		$_POST                = [ 'phone' => $phone ];
		$_REQUEST['_wpnonce'] = wp_create_nonce( SmsVerificationController::ACTION_SEND );

		return AjaxRequests::dispatch( [ SmsVerificationController::class, 'send' ] );
	}

	/**
	 * Build a wp_remote_* response array.
	 *
	 * @param array<string, mixed> $payload JSON payload.
	 * @param int                  $code    HTTP status code.
	 * @return array<string, mixed>
	 */
	private function httpResponse( array $payload, int $code ): array {
		return [
			'headers'  => [],
			'body'     => (string) wp_json_encode( $payload ),
			'response' => [
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_POST, $_REQUEST['_wpnonce'] );
		remove_all_filters( 'pre_http_request' );

		parent::tearDown();
	}
}
