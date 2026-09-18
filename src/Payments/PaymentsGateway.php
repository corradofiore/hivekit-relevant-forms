<?php
/**
 * Checkout gateway contract (payments doc §5).
 *
 * The plugin never talks to Stripe directly: sessions are created by the
 * Relevant Cloud on the owner's connected account and referenced by id.
 * Implementations return null whenever the service is unavailable — the
 * caller then leaves the entry pending without disturbing the visitor.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Payments;

use RelevantForms\Forms\Form;

/**
 * Creates and tracks hosted checkout sessions.
 */
interface PaymentsGateway {

	/**
	 * Whether the checkout service is configured and consented to.
	 */
	public function isAvailable(): bool;

	/**
	 * Create a checkout session for a pending entry.
	 *
	 * @param Form                                                                                                                                   $form      Form entity.
	 * @param int                                                                                                                                    $entryId   Stored entry ID.
	 * @param array{currency: string, total_minor: int, items: array<int, array{field_id: string, label: string, detail: string, amount_minor: int}>} $payment   Server-computed breakdown.
	 * @param string                                                                                                                                 $sourceUrl Page the form was submitted from (return base).
	 * @return array<string, mixed>|null Session data: id, checkout_url.
	 */
	public function createSession( Form $form, int $entryId, array $payment, string $sourceUrl ): ?array;

	/**
	 * Current status of a checkout session.
	 *
	 * @param string $sessionId Checkout session reference.
	 * @return array<string, mixed>|null Session state: status (paid|pending|failed), payment_intent, amount_minor.
	 */
	public function sessionStatus( string $sessionId ): ?array;

	/**
	 * Refund a paid session (full amount, v1).
	 *
	 * @param string $sessionId Checkout session reference.
	 * @return array<string, mixed>|null Refund data: refund_id.
	 */
	public function refund( string $sessionId ): ?array;
}
