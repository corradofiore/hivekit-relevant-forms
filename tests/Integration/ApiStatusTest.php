<?php
/**
 * API status test integration tests: the Diagnostics screen checks the
 * Cloud health endpoint on demand (admin-ajax).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\ApiStatusController;
use RelevantForms\Admin\Pages\DiagnosticsPage;
use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Tests\Integration\Support\AjaxRequests;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class ApiStatusTest extends TestCase {

	/**
	 * Administrator context.
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_POST, $_REQUEST['_wpnonce'] );

		parent::tearDown();
	}

	/**
	 * The Diagnostics screen shows the API status test and no provider rows.
	 */
	public function testTheDiagnosticsScreenShowsTheApiStatusTest(): void {
		ob_start();
		DiagnosticsPage::render();

		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'API status', $html );
		$this->assertStringContainsString( 'Test API connection', $html );
		$this->assertStringContainsString( 'id="rf-test-api-status"', $html );
		$this->assertStringContainsString( 'data-action="' . ApiStatusController::ACTION . '"', $html );

		// The endpoint URL stays hidden; only the test result is shown.
		$this->assertStringNotContainsString( CloudConfig::apiBase(), $html );

		// The old provider rows are gone.
		$this->assertStringNotContainsString( 'AI provider', $html );
		$this->assertStringNotContainsString( 'SMS provider', $html );
		$this->assertStringNotContainsString( 'Storage provider', $html );
		$this->assertStringNotContainsString( 'Malware scanner', $html );
		$this->assertStringNotContainsString( 'Deep scan backend', $html );
	}

	/**
	 * The Diagnostics screen names the service this build talks to, and the
	 * channel it came from.
	 */
	public function testTheDiagnosticsScreenReportsTheServiceAndChannel(): void {
		ob_start();
		DiagnosticsPage::render();

		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Service endpoint', $html );
		$this->assertStringContainsString( CloudConfig::url(), $html );

		// The versioned path stays internal: the status section below
		// deliberately keeps the full API base off the page.
		$this->assertStringNotContainsString( CloudConfig::apiBase(), $html );

		// A shipped source tree is the production channel; only a QA build
		// stamps itself otherwise, and then it says so loudly.
		$this->assertStringContainsString( 'Build channel', $html );
		$this->assertStringContainsString( 'Production', $html );
		$this->assertStringNotContainsString( 'pre-release', $html );
	}

	/**
	 * The Diagnostics screen reports the outbound mail transport, and warns
	 * when nothing owns it.
	 */
	public function testTheDiagnosticsScreenReportsTheMailTransport(): void {
		ob_start();
		DiagnosticsPage::render();

		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Outbound email', $html );
		$this->assertStringContainsString( 'No SMTP transport detected', $html );
		$this->assertStringContainsString( 'notice-warning', $html );

		$configure = static function ( $phpmailer ): void {
			$phpmailer->isSMTP();
		};

		add_action( 'phpmailer_init', $configure );

		try {
			ob_start();
			DiagnosticsPage::render();

			$html = (string) ob_get_clean();
		} finally {
			remove_action( 'phpmailer_init', $configure );
		}

		$this->assertStringContainsString( 'An SMTP or relay mailer is active.', $html );
		$this->assertStringNotContainsString( 'notice-warning', $html );
	}

	/**
	 * The test reports an online API with its version and round trip.
	 */
	public function testTheStatusTestReportsAnOnlineApi(): void {
		$requested = '';

		add_filter(
			'pre_http_request',
			static function ( mixed $pre, array $args, string $url ) use ( &$requested ): array {
				unset( $pre, $args ); // Canned health response; the URL is asserted below.

				$requested = $url;

				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'success' => true,
							'data'    => [
								'status'  => 'ok',
								'app'     => 'Relevant Cloud',
								'version' => '12.1',
								'time'    => '2026-10-01T10:00:00+00:00',
							],
						]
					),
				];
			},
			10,
			3
		);

		$body = $this->dispatch();

		$this->assertTrue( $body['success'] );
		$this->assertSame( CloudConfig::apiBase() . '/health', $requested );
		$this->assertArrayHasKey( 'latency_ms', $body['data'] );

		// The message only reports the round trip; service details stay hidden.
		$this->assertMatchesRegularExpression( '/^The API responded in \d+ msec\.$/', (string) $body['data']['message'] );
		$this->assertArrayNotHasKey( 'app', $body['data'] );
		$this->assertArrayNotHasKey( 'version', $body['data'] );
	}

	/**
	 * A network failure is reported as unreachable, with the reason.
	 */
	public function testTheStatusTestReportsAnUnreachableApi(): void {
		add_filter(
			'pre_http_request',
			static function (): \WP_Error {
				return new \WP_Error( 'http_request_failed', 'Connection timed out after 10 seconds' );
			}
		);

		$body = $this->dispatch();

		$this->assertFalse( $body['success'] );
		$this->assertSame( 'unreachable', $body['data']['code'] );
		$this->assertStringContainsString( 'Connection timed out', (string) $body['data']['message'] );
	}

	/**
	 * An unexpected status code is reported with the code.
	 */
	public function testTheStatusTestReportsAnUnexpectedStatus(): void {
		add_filter(
			'pre_http_request',
			static fn (): array => [
				'response' => [ 'code' => 503 ],
				'body'     => '',
			]
		);

		$body = $this->dispatch();

		$this->assertFalse( $body['success'] );
		$this->assertSame( 'unreachable', $body['data']['code'] );
		$this->assertStringContainsString( '503', (string) $body['data']['message'] );
	}

	/**
	 * Dispatch the test endpoint as an AJAX request.
	 *
	 * @return array<string, mixed> Decoded response body.
	 */
	private function dispatch(): array {
		$_REQUEST['_wpnonce'] = wp_create_nonce( ApiStatusController::ACTION );

		return AjaxRequests::dispatch( [ ApiStatusController::class, 'test' ] );
	}
}
