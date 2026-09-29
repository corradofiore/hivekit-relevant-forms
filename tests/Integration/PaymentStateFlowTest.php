<?php
/**
 * Payment state integration tests (payments phase 2): pending entries,
 * checkout redirect, deferred notifications, the no-JS return leg, refunds
 * and the consent gate — against a simulated cloud (pre_http_request).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Events\EventRecorder;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Forms\SubmissionFeedback;
use RelevantForms\Http\PaymentReturnHandler;
use RelevantForms\Http\SubmissionHandler;
use RelevantForms\Payments\PaymentService;
use RelevantForms\Tests\Integration\Support\CapturesMail;
use RelevantForms\Tests\Integration\Support\CapturesRedirects;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class PaymentStateFlowTest extends TestCase {

	use CapturesMail;
	use CapturesRedirects;

	/**
	 * Captured outbound HTTP requests: url + args.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = [];

	/**
	 * Session status the simulated cloud reports back.
	 *
	 * @var string
	 */
	private string $session_status = 'paid';

	/**
	 * Simulated request environment.
	 */
	public function setUp(): void {
		parent::setUp();

		SubmissionFeedback::reset();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/payments/';
		$_SERVER['HTTP_REFERER']   = home_url( '/payments/' );

		update_option(
			'relevant_forms_settings',
			[
				'cloud_url'     => 'https://cloud.example.com',
				'cloud_api_key' => 'site-key',
			]
		);

		$this->requests = [];

		$this->captureMail();
	}

	/**
	 * A payment submission stores the pending entry, redirects to checkout
	 * and holds every notification back.
	 */
	public function testPendingSubmissionRedirectsToCheckoutWithoutNotifying(): void {
		$this->enablePayments();
		$this->fakeCloud();

		$form = $this->makePaymentForm();

		$url = $this->submitPaymentForm( $form, '1' );

		$this->assertSame( 'https://checkout.stripe.com/c/pay/cs_test_1', $url );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertCount( 1, $entries );

		$entry = $entries[0];
		$this->assertSame( Entry::STATUS_PENDING_PAYMENT, $entry->status );

		// Sanitized storage keeps the human label, never the position.
		$this->assertSame( 'Cap', $entry->meta['f_merch'] );

		$payment = $entry->meta['_payment'] ?? [];
		$this->assertSame( 'pending', $payment['status'] ?? '' );
		$this->assertSame( 'usd', $payment['currency'] ?? '' );
		$this->assertSame( 3500, (int) ( $payment['total_minor'] ?? 0 ) );
		$this->assertSame( 'cs_test_1', $payment['session_id'] ?? '' );
		$this->assertCount( 2, $payment['items'] ?? [] );

		$names = array_column( EventRecorder::forEntry( (int) $entry->id ), 'event' );
		$this->assertContains( 'payment_pending', $names );
		$this->assertContains( 'payment_session_created', $names );
		$this->assertContains( 'notification_deferred', $names );

		$this->assertSame( [], $this->sent_mail );

		// The session request carried the server-computed total and the
		// return URLs with the entry reference and session placeholder.
		$body = $this->sessionRequestBody();

		$this->assertSame( 3500, (int) $body['total_minor'] );
		$this->assertSame( 'usd', $body['currency'] );
		$this->assertStringContainsString( 'relevant_payment=return', (string) $body['success_url'] );
		$this->assertStringContainsString( 'entry=' . $entry->id, (string) $body['success_url'] );
		$this->assertStringContainsString( '{CHECKOUT_SESSION_ID}', (string) $body['success_url'] );
		$this->assertStringContainsString( 'relevant_payment=cancel', (string) $body['cancel_url'] );
	}

	/**
	 * The no-JS return leg verifies the session, flips the entry to paid
	 * and fires the deferred notification exactly once.
	 */
	public function testReturnLegMarksPaidAndNotifies(): void {
		$this->enablePayments();
		$this->fakeCloud();

		$form  = $this->makePaymentForm();
		$entry = $this->submitPending( $form );

		$this->assertSame( [], $this->sent_mail );

		$url = $this->handleReturn(
			[
				'relevant_payment' => 'return',
				'entry'            => $entry->id,
				'session'          => 'cs_test_1',
			]
		);

		$this->assertStringContainsString( 'relevant_form_success=' . $form->id, $url );

		$entry = ( new EntryRepository() )->find( (int) $entry->id );
		$this->assertSame( Entry::STATUS_PAID, $entry->status );

		$payment = $entry->meta['_payment'] ?? [];
		$this->assertSame( 'paid', $payment['status'] ?? '' );
		$this->assertSame( 'pi_test_1', $payment['payment_intent'] ?? '' );
		$this->assertSame( 3500, (int) ( $payment['amount_received_minor'] ?? 0 ) );

		$names = array_column( EventRecorder::forEntry( (int) $entry->id ), 'event' );
		$this->assertContains( 'payment_paid', $names );

		$this->assertNotEmpty( $this->sent_mail );
		$this->assertStringContainsString( 'Payments', (string) $this->sent_mail[0]['subject'] );

		// Idempotent: a repeated return or the webhook changes nothing.
		$this->assertFalse( ( new PaymentService() )->markPaid( (int) $entry->id ) );
		$this->assertCount( 1, $this->sent_mail );
	}

	/**
	 * A returning visitor whose session expired flips to payment_failed and
	 * sees the retry notice instead of the success message.
	 */
	public function testCancelWithExpiredSessionMarksFailed(): void {
		$this->enablePayments();
		$this->fakeCloud();

		$form  = $this->makePaymentForm();
		$entry = $this->submitPending( $form );

		$this->session_status = 'expired';

		$url = $this->handleReturn(
			[
				'relevant_payment' => 'cancel',
				'entry'            => $entry->id,
				'session'          => 'cs_test_1',
			]
		);

		$this->assertStringContainsString( 'relevant_payment_failed=' . $form->id, $url );

		$entry = ( new EntryRepository() )->find( (int) $entry->id );
		$this->assertSame( Entry::STATUS_PAYMENT_FAILED, $entry->status );
		$this->assertSame( 'failed', $entry->meta['_payment']['status'] ?? '' );

		$this->assertSame( [], $this->sent_mail );
	}

	/**
	 * Refunds only apply to paid entries and happen once.
	 */
	public function testRefundsFlipPaidEntriesOnce(): void {
		$this->enablePayments();
		$this->fakeCloud();

		$form  = $this->makePaymentForm();
		$entry = $this->submitPending( $form );

		$service = new PaymentService();

		$this->assertTrue( $service->markPaid( (int) $entry->id ) );
		$this->assertTrue( $service->markRefunded( (int) $entry->id, 're_test_1' ) );

		$entry = ( new EntryRepository() )->find( (int) $entry->id );
		$this->assertSame( Entry::STATUS_REFUNDED, $entry->status );
		$this->assertSame( 'refunded', $entry->meta['_payment']['status'] ?? '' );
		$this->assertSame( 're_test_1', $entry->meta['_payment']['refund_id'] ?? '' );

		// Illegal repeats are guarded.
		$this->assertFalse( $service->markRefunded( (int) $entry->id ) );
		$this->assertFalse( $service->markFailed( (int) $entry->id ) );
	}

	/**
	 * Without the payments capability nothing external happens: the entry
	 * stays pending and the visitor gets the normal confirmation.
	 */
	public function testWithoutPaymentsCapabilityTheEntryStaysPending(): void {
		$this->fakeCloud();

		$form = $this->makePaymentForm();

		$url = $this->submitPaymentForm( $form, '1' );

		$this->assertStringContainsString( 'relevant_form_success=' . $form->id, $url );

		$entry = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] )[0];
		$this->assertSame( Entry::STATUS_PENDING_PAYMENT, $entry->status );

		$names = array_column( EventRecorder::forEntry( (int) $entry->id ), 'event' );
		$this->assertContains( 'payment_session_failed', $names );

		// No session creation, no notification without consent.
		foreach ( $this->requests as $request ) {
			$this->assertStringNotContainsString( '/payments/sessions', (string) $request['url'] );
		}

		$this->assertSame( [], $this->sent_mail );
	}

	/**
	 * A form whose payment total is zero (display-only total) behaves like
	 * any other submission.
	 */
	public function testZeroTotalFormsSubmitNormally(): void {
		$this->enablePayments();
		$this->fakeCloud();

		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Quote' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
				[
					'id'    => 'f_total',
					'type'  => 'payment-total',
					'label' => 'Total',
				],
			]
		);

		$formId = $repository->create( 'Quote', $schema, 'published' );
		$form   = $repository->find( $formId );

		$this->assertNotNull( $form );

		$url = $this->submitPaymentForm( $form, '1' );

		$this->assertStringContainsString( 'relevant_form_success=' . $form->id, $url );

		$entry = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] )[0];
		$this->assertSame( Entry::STATUS_NEW, $entry->status );
		$this->assertArrayNotHasKey( '_payment', $entry->meta );

		$this->assertNotEmpty( $this->sent_mail );
	}

	/**
	 * Consent: connect the simulated account and enable payments.
	 */
	private function enablePayments(): void {
		$connection = new CloudConnection();
		$connection->connect( 'site-uuid-pay', 'https://a.example', 'Acme' );
		$connection->enableCapability( CloudConnection::CAP_PAYMENTS );
	}

	/**
	 * Simulated cloud: session creation + status polling.
	 */
	private function fakeCloud(): void {
		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array {
				unset( $pre );

				$this->requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				if ( str_ends_with( $url, '/api/v1/payments/sessions' ) ) {
					return $this->cloudResponse(
						[
							'session' => [
								'id'           => 'cs_test_1',
								'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
							],
						],
						201
					);
				}

				if ( str_contains( $url, '/api/v1/payments/sessions/cs_test_1' ) ) {
					return $this->cloudResponse(
						[
							'session' => [
								'status'         => $this->session_status,
								'payment_intent' => 'pi_test_1',
								'amount_minor'   => 3500,
							],
						]
					);
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
	 * A payment form: fixed deposit, priced dropdown and total line.
	 */
	private function makePaymentForm(): Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Payments' )
			->withCurrency( 'usd' )
			->withFields(
				[
					[
						'id'     => 'f_deposit',
						'type'   => 'payment-single',
						'label'  => 'Deposit',
						'amount' => '25.00',
					],
					[
						'id'      => 'f_merch',
						'type'    => 'payment-select',
						'label'   => 'Merch',
						'choices' => [ 'T-shirt|25.00', 'Cap|10.00' ],
					],
					[
						'id'    => 'f_total',
						'type'  => 'payment-total',
						'label' => 'Total',
					],
				]
			);

		$id = $repository->create( 'Payments', $schema, 'published' );

		return $repository->find( $id );
	}

	/**
	 * Submit the form with the given choice position; returns the redirect.
	 *
	 * @param Form   $form   Form to submit.
	 * @param string $choice Posted choice position.
	 */
	private function submitPaymentForm( Form $form, string $choice ): string {
		// Real requests populate $_REQUEST from the GET/POST order.
		$_REQUEST['_wp_http_referer'] = '/payments/';

		return $this->captureRedirect(
			[ SubmissionHandler::class, 'maybeHandleFrontendSubmission' ],
			'',
			[
				'action'                 => 'relevant_forms_submit',
				'_relevant_form_id'      => $form->id,
				'_relevant_form_nonce'   => wp_create_nonce( 'relevant_form_' . $form->id ),
				'_wp_http_referer'       => '/payments/',
				'relevant_field_f_merch' => $choice,
			]
		);
	}

	/**
	 * Submit a payment form and return the stored pending entry.
	 *
	 * @param Form $form Form to submit.
	 */
	private function submitPending( Form $form ): Entry {
		$this->submitPaymentForm( $form, '1' );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );

		$this->assertNotSame( [], $entries );

		return $entries[0];
	}

	/**
	 * Run the return handler with the given query args and capture the
	 * redirect target.
	 *
	 * @param array<string, mixed> $query Query args for the simulated return request.
	 */
	private function handleReturn( array $query ): string {
		$_GET                      = $query;
		$_SERVER['REQUEST_METHOD'] = 'GET';

		try {
			return $this->captureRedirect( [ PaymentReturnHandler::class, 'maybeHandle' ] );
		} finally {
			$_GET = [];
		}
	}

	/**
	 * Decoded body of the captured checkout session request.
	 *
	 * @return array<string, mixed>
	 */
	private function sessionRequestBody(): array {
		foreach ( $this->requests as $request ) {
			if ( str_ends_with( (string) $request['url'], '/api/v1/payments/sessions' ) ) {
				$decoded = json_decode( (string) $request['args']['body'], true );

				$this->assertIsArray( $decoded );

				return $decoded;
			}
		}

		$this->fail( 'No checkout session request was made.' );

		return [];
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'relevant_forms_redirect' );
		$_GET = [];

		parent::tearDown();
	}
}
