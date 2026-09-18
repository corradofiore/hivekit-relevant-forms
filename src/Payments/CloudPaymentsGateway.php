<?php
/**
 * Cloud-backed checkout gateway (payments doc §5).
 *
 * Consent-gated like the other external capabilities: without a connected
 * account and the explicitly enabled `payments` capability, no request is
 * made and the visitor flow is unaffected (v2 §7–8).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Payments;

use RelevantForms\Cloud\CloudClient;
use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Forms\Form;
use RelevantForms\Support\Logger;

/**
 * Talks to the Relevant Cloud, which brokers the owner's Stripe account.
 */
final class CloudPaymentsGateway implements PaymentsGateway {

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		if ( ! CloudConfig::isConfigured() ) {
			return false;
		}

		return ( new CloudConnection() )->capabilityEnabled( CloudConnection::CAP_PAYMENTS );
	}

	/**
	 * {@inheritDoc}
	 */
	public function createSession( Form $form, int $entryId, array $payment, string $sourceUrl ): ?array {
		$data = $this->client()->createPaymentSession(
			[
				'form_id'     => $form->id,
				'form_name'   => $form->name,
				'entry_id'    => $entryId,
				'currency'    => $payment['currency'],
				'total_minor' => $payment['total_minor'],
				'items'       => array_map(
					static function ( array $item ): array {
						$name = (string) $item['label'];

						if ( '' !== (string) $item['detail'] ) {
							$name .= ' — ' . (string) $item['detail'];
						}

						return [
							'name'         => $name,
							'amount_minor' => $item['amount_minor'],
							'quantity'     => 1,
						];
					},
					$payment['items']
				),
				'success_url' => self::returnUrl( $sourceUrl, $entryId, true ),
				'cancel_url'  => self::returnUrl( $sourceUrl, $entryId, false ),
			]
		);

		if ( null === $data ) {
			return null;
		}

		$session = is_array( $data['session'] ?? null ) ? $data['session'] : $data;

		$id = (string) ( $session['id'] ?? '' );

		if ( '' === $id ) {
			Logger::warning( 'Checkout session response carried no id', [ 'entry_id' => $entryId ] );

			return null;
		}

		return [
			'id'           => $id,
			'checkout_url' => (string) ( $session['checkout_url'] ?? $session['url'] ?? '' ),
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function sessionStatus( string $sessionId ): ?array {
		$data = $this->client()->paymentSession( $sessionId );

		if ( null === $data ) {
			return null;
		}

		$session = is_array( $data['session'] ?? null ) ? $data['session'] : $data;

		return [
			'status'         => (string) ( $session['status'] ?? '' ),
			'payment_intent' => (string) ( $session['payment_intent'] ?? '' ),
			'amount_minor'   => isset( $session['amount_minor'] ) ? (int) $session['amount_minor'] : null,
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function refund( string $sessionId ): ?array {
		$data = $this->client()->refundPayment( $sessionId );

		if ( null === $data ) {
			return null;
		}

		$refund = is_array( $data['refund'] ?? null ) ? $data['refund'] : $data;

		return [ 'refund_id' => (string) ( $refund['id'] ?? $refund['refund_id'] ?? '' ) ];
	}

	/**
	 * Return URL the checkout service redirects to: the page the form was
	 * submitted from, marked so PaymentReturnHandler verifies the session
	 * server-side (no-JS path included).
	 *
	 * @param string $sourceUrl Submission page URL.
	 * @param int    $entryId   Stored entry ID.
	 * @param bool   $success   Whether this is the success leg.
	 */
	private static function returnUrl( string $sourceUrl, int $entryId, bool $success ): string {
		$base = '' !== $sourceUrl ? $sourceUrl : home_url( '/' );

		$url = add_query_arg(
			[
				'relevant_payment' => $success ? 'return' : 'cancel',
				'entry'            => $entryId,
			],
			$base
		);

		if ( ! $success ) {
			return $url;
		}

		// Stripe substitutes the literal placeholder in success URLs.
		return $url . '&session={CHECKOUT_SESSION_ID}';
	}

	/**
	 * Cloud API client for the configured account.
	 */
	private function client(): CloudClient {
		return new CloudClient( CloudConfig::url(), CloudConfig::key() );
	}
}
