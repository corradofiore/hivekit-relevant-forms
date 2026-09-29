<?php
/**
 * Payments wiring integration tests (payments phase 4): settings connect
 * flow, return handling, stored snapshots, the entry payment panel and
 * the check-status / refund admin actions — against a simulated cloud.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\PaymentEntryController;
use RelevantForms\Admin\Controllers\PaymentSettingsController;
use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Tests\Integration\Support\CapturesMail;
use RelevantForms\Tests\Integration\Support\CapturesRedirects;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class PaymentWiringFlowTest extends TestCase {

	use CapturesMail;
	use CapturesRedirects;

	/**
	 * Captured outbound HTTP requests.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = [];

	/**
	 * Simulated request environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$_SERVER['REQUEST_METHOD'] = 'POST';

		update_option(
			'relevant_forms_settings',
			[
				'cloud_url'     => 'https://cloud.example.com',
				'cloud_api_key' => 'site-key',
			]
		);

		$this->requests = [];

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// Notifications must never leave the test site.
		$this->captureMail();
	}

	/**
	 * The connect button asks the backend for a state-bound authorize URL
	 * and forwards the browser to Stripe.
	 */
	public function testConnectRedirectsToTheStripeAuthorizeUrl(): void {
		$this->connectCloud();

		$this->fakeCloud(
			[
				'/api/v1/payments/connect' => $this->cloudResponse(
					[
						'authorize_url' => 'https://connect.stripe.com/oauth/authorize?client_id=ca_test&state=abc',
						'state'         => 'abc',
						'expires_in'    => 1800,
					]
				),
			]
		);

		$url = $this->capture(
			static function (): void {
				PaymentSettingsController::connect();
			},
			'relevant_payments_connect'
		);

		$this->assertSame( 'https://connect.stripe.com/oauth/authorize?client_id=ca_test&state=abc', $url );
		$this->assertStringContainsString( '/api/v1/payments/connect', $this->requests[0]['url'] );
		$this->assertStringContainsString( 'site-key', (string) $this->requests[0]['args']['headers']['Authorization'] );
	}

	/**
	 * The Cloud return marker is verified against the backend before the
	 * capability is enabled and the snapshot is stored.
	 */
	public function testReturnFlagEnablesTheCapabilityOnlyForRealConnections(): void {
		$this->connectCloud();

		$_GET = [
			'page'              => 'relevant-settings',
			'relevant_payments' => 'connected',
		];

		$this->fakeCloud(
			[
				'/api/v1/payments/connect/status' => $this->cloudResponse(
					[
						'connected'       => true,
						'stripe_user_id'  => 'acct_test_1',
						'livemode'        => false,
						'charges_enabled' => true,
						'payouts_enabled' => true,
						'fee_percent'     => 2.0,
					]
				),
			]
		);

		$url = $this->capture(
			static function (): void {
				PaymentSettingsController::maybeCompleteReturn();
			}
		);

		$this->assertStringContainsString( 'payments=connected', $url );

		$connection = new CloudConnection();
		$this->assertTrue( $connection->capabilityEnabled( CloudConnection::CAP_PAYMENTS ) );
		$this->assertSame( 'acct_test_1', $connection->payments()['stripe_user_id'] ?? '' );

		// A false positive from the backend never enables the capability.
		$this->removeCloudFilters();
		$_GET = [
			'page'              => 'relevant-settings',
			'relevant_payments' => 'connected',
		];

		$this->fakeCloud(
			[
				'/api/v1/payments/connect/status' => $this->cloudResponse( [ 'connected' => false ] ),
			]
		);

		$url = $this->capture(
			static function (): void {
				PaymentSettingsController::maybeCompleteReturn();
			}
		);

		$this->assertStringContainsString( 'payments=error', $url );
	}

	/**
	 * Provider errors bounce back with the error marker and no HTTP call.
	 */
	public function testReturnErrorFlagSkipsTheBackend(): void {
		$_GET = [
			'page'              => 'relevant-settings',
			'relevant_payments' => 'error',
		];

		$url = $this->capture(
			static function (): void {
				PaymentSettingsController::maybeCompleteReturn();
			}
		);

		$this->assertStringContainsString( 'payments=error', $url );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Refresh stores the backend snapshot; disconnect clears consent.
	 */
	public function testRefreshAndDisconnectActions(): void {
		$this->connectCloud();

		$connection = new CloudConnection();
		$connection->enableCapability( CloudConnection::CAP_PAYMENTS );

		$this->fakeCloud(
			[
				'/api/v1/payments/connect/status' => $this->cloudResponse(
					[
						'connected'      => true,
						'stripe_user_id' => 'acct_test_1',
						'fee_percent'    => 2.0,
					]
				),
			]
		);

		$url = $this->capture(
			static function (): void {
				PaymentSettingsController::refresh();
			},
			'relevant_payments_refresh'
		);

		$this->assertStringContainsString( 'payments=connected', $url );
		$this->assertSame( 'acct_test_1', $connection->payments()['stripe_user_id'] ?? '' );

		$this->removeCloudFilters();
		$this->fakeCloud(
			[
				'/api/v1/payments/connect/disconnect' => $this->cloudResponse( [ 'connected' => false ] ),
			]
		);

		$url = $this->capture(
			static function (): void {
				PaymentSettingsController::disconnect();
			},
			'relevant_payments_disconnect'
		);

		$this->assertStringContainsString( 'payments=disconnected', $url );
		$this->assertFalse( $connection->capabilityEnabled( CloudConnection::CAP_PAYMENTS ) );
		$this->assertEmpty( $connection->payments()['connected'] ?? null );
	}

	/**
	 * The entry screen renders the payment record and the right actions.
	 */
	public function testEntryPaymentPanelRendersDetailsAndActions(): void {
		$this->connectCloud();
		( new CloudConnection() )->enableCapability( CloudConnection::CAP_PAYMENTS );

		$form = $this->makeForm();
		$paid = $this->makeEntry(
			$form,
			[
				'f_name'   => 'Ada Lovelace',
				'_payment' => [
					'status'         => 'paid',
					'currency'       => 'usd',
					'total_minor'    => 3500,
					'items'          => [
						[
							'field_id'     => 'f_deposit',
							'label'        => 'Deposit',
							'detail'       => '',
							'amount_minor' => 2500,
						],
					],
					'session_id'     => 'cs_test_1',
					'payment_intent' => 'pi_test_1',
					'created_at'     => '2026-09-18 10:00:00',
					'paid_at'        => '2026-09-18 10:05:00',
				],
			],
			Entry::STATUS_PAID
		);

		$html = $this->renderEntryView( $paid, $form );

		$this->assertStringContainsString( 'Payment', $html );
		$this->assertStringContainsString( '$35.00', $html );
		$this->assertStringContainsString( 'cs_test_1', $html );
		$this->assertStringContainsString( 'Refund payment', $html );
		$this->assertStringNotContainsString( 'Check payment status', $html );

		$pending = $this->makeEntry(
			$form,
			[
				'_payment' => [
					'status'      => 'pending',
					'currency'    => 'usd',
					'total_minor' => 2500,
					'items'       => [],
					'session_id'  => 'cs_test_2',
				],
			]
		);

		$html = $this->renderEntryView( $pending, $form );

		$this->assertStringContainsString( 'Check payment status', $html );
		$this->assertStringNotContainsString( 'Refund payment', $html );
	}

	/**
	 * Check-status flips a pending entry once the cloud reports paid.
	 */
	public function testCheckStatusActionFlipsPendingEntries(): void {
		$this->connectCloud();
		( new CloudConnection() )->enableCapability( CloudConnection::CAP_PAYMENTS );

		$form  = $this->makeForm();
		$entry = $this->makeEntry(
			$form,
			[
				'_payment' => [
					'status'      => 'pending',
					'currency'    => 'usd',
					'total_minor' => 2500,
					'items'       => [],
					'session_id'  => 'cs_test_1',
				],
			]
		);

		$this->fakeCloud(
			[
				'/api/v1/payments/sessions/cs_test_1' => $this->cloudResponse(
					[
						'session' => [
							'status'         => 'paid',
							'payment_intent' => 'pi_test_1',
							'amount_minor'   => 2500,
						],
					]
				),
			]
		);

		$url = $this->capture(
			static function (): void {
				PaymentEntryController::check();
			},
			'relevant_entry_payment_check_' . $entry,
			[ 'entry_id' => $entry ]
		);

		$this->assertStringContainsString( 'payment_notice=paid', $url );

		$stored = ( new EntryRepository() )->find( $entry );
		$this->assertSame( Entry::STATUS_PAID, $stored->status );
		$this->assertSame( 'paid', $stored->meta['_payment']['status'] ?? '' );
		$this->assertSame( 'pi_test_1', $stored->meta['_payment']['payment_intent'] ?? '' );
	}

	/**
	 * Refunds require a paid entry; failures leave the state untouched.
	 */
	public function testRefundActionGuardsAndFlips(): void {
		$this->connectCloud();
		( new CloudConnection() )->enableCapability( CloudConnection::CAP_PAYMENTS );

		$form = $this->makeForm();

		$pending = $this->makeEntry(
			$form,
			[
				'_payment' => [
					'status'     => 'pending',
					'currency'   => 'usd',
					'session_id' => 'cs_pending',
				],
			]
		);

		// Not paid: rejected before any API call.
		$url = $this->capture(
			static function (): void {
				PaymentEntryController::refund();
			},
			'relevant_entry_payment_refund_' . $pending,
			[ 'entry_id' => $pending ]
		);

		$this->assertStringContainsString( 'payment_notice=not_paid', $url );
		$this->assertSame( [], $this->requests );

		$paid = $this->makeEntry(
			$form,
			[
				'_payment' => [
					'status'         => 'paid',
					'currency'       => 'usd',
					'total_minor'    => 2500,
					'session_id'     => 'cs_paid',
					'payment_intent' => 'pi_paid',
				],
			],
			Entry::STATUS_PAID
		);

		// Provider failure keeps the entry paid.
		$this->fakeCloud(
			[
				'/api/v1/payments/cs_paid/refund' => [
					'response' => [ 'code' => 502 ],
					'body'     => '{}',
				],
			]
		);

		$url = $this->capture(
			static function (): void {
				PaymentEntryController::refund();
			},
			'relevant_entry_payment_refund_' . $paid,
			[ 'entry_id' => $paid ]
		);

		$this->assertStringContainsString( 'payment_notice=refund_failed', $url );
		$this->assertSame( Entry::STATUS_PAID, ( new EntryRepository() )->find( $paid )->status );

		// Successful refund flips the entry and stores the reference.
		$this->removeCloudFilters();
		$this->fakeCloud(
			[
				'/api/v1/payments/cs_paid/refund' => $this->cloudResponse(
					[
						'refund' => [
							'id'           => 're_test_1',
							'status'       => 'refunded',
							'amount_minor' => 2500,
						],
					]
				),
			]
		);

		$url = $this->capture(
			static function (): void {
				PaymentEntryController::refund();
			},
			'relevant_entry_payment_refund_' . $paid,
			[ 'entry_id' => $paid ]
		);

		$this->assertStringContainsString( 'payment_notice=refunded', $url );

		$stored = ( new EntryRepository() )->find( $paid );
		$this->assertSame( Entry::STATUS_REFUNDED, $stored->status );
		$this->assertSame( 're_test_1', $stored->meta['_payment']['refund_id'] ?? '' );
	}

	/**
	 * Bind the site to the simulated cloud account.
	 */
	private function connectCloud(): void {
		$connection = new CloudConnection();
		$connection->connect( 'site-uuid-wiring', 'https://a.example', 'Acme' );
	}

	/**
	 * Simulated cloud: routes by URL fragment.
	 *
	 * @param array<string, array<string, mixed>> $responses URL fragment → response.
	 */
	private function fakeCloud( array $responses ): void {
		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ) use ( $responses ): array {
				unset( $pre );

				$this->requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				foreach ( $responses as $fragment => $response ) {
					if ( str_contains( $url, $fragment ) ) {
						return $response;
					}
				}

				return [
					'response' => [ 'code' => 404 ],
					'body'     => '{}',
				];
			},
			10,
			3
		);
	}

	/**
	 * Remove the simulated cloud.
	 */
	private function removeCloudFilters(): void {
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * One simulated cloud API response.
	 *
	 * @param array<string, mixed> $data Envelope data.
	 * @param int                  $code HTTP status.
	 * @return array<string, mixed>
	 */
	private function cloudResponse( array $data, int $code = 200 ): array {
		return [
			'response' => [ 'code' => $code ],
			'body'     => (string) wp_json_encode(
				[
					'success' => true,
					'data'    => $data,
				]
			),
		];
	}

	/**
	 * Run an admin action with a nonce and capture the redirect target.
	 *
	 * @param callable             $action      Handler under test.
	 * @param string               $nonceAction Nonce action name.
	 * @param array<string, mixed> $post        Extra POST data.
	 */
	private function capture( callable $action, string $nonceAction = '', array $post = [] ): string {
		if ( '' !== $nonceAction ) {
			$_POST = $post;
		}

		return $this->captureRedirect( $action, $nonceAction );
	}

	/**
	 * Create a published form with one text field.
	 */
	private function makeForm(): Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Payments' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
			]
		);

		$id = $repository->create( 'Payments', $schema, 'published' );

		return $repository->find( $id );
	}

	/**
	 * Store an entry with the given meta.
	 *
	 * @param Form                  $form   Owning form.
	 * @param array<string, mixed>  $meta   Entry meta.
	 * @param string                $status Entry status.
	 */
	private function makeEntry( Form $form, array $meta, string $status = Entry::STATUS_PENDING_PAYMENT ): int {
		$repository = new EntryRepository();

		$entry = new Entry(
			null,
			$form->id,
			( new FormRepository() )->createVersion( $form->id, $form->schema ),
			$status,
			'https://a.example',
			'',
			'',
			'',
			current_time( 'mysql', true ),
			current_time( 'mysql', true ),
			$meta,
		);

		return $repository->create( $entry );
	}

	/**
	 * Render the entry detail template for one entry.
	 *
	 * @param int  $entryId Entry ID.
	 * @param Form $form    Owning form.
	 */
	private function renderEntryView( int $entryId, Form $form ): string {
		$entry   = ( new EntryRepository() )->find( $entryId );
		$events  = [];
		$uploads = [];

		ob_start();
		require RELEVANT_FORMS_PATH . 'templates/admin/entry-view.php';

		return (string) ob_get_clean();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'relevant_forms_redirect' );
		$_GET = [];

		parent::tearDown();
	}
}
