<?php
/**
 * Cloud connection and consent state machine (v2 §8).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Cloud;

/**
 * Tracks the explicit Cloud consent state. Core never transmits form
 * content to Cloud capabilities that are not explicitly enabled.
 */
final class CloudConnection {

	/**
	 * Option key.
	 */
	private const OPTION = 'relevant_cloud_connection';

	/**
	 * Disconnected: no Cloud calls of any kind.
	 */
	public const STATE_DISCONNECTED = 'disconnected';

	/**
	 * Site key bound to a Cloud account.
	 */
	public const STATE_ACCOUNT_CONNECTED = 'account_connected';

	/**
	 * Semantic classification enabled.
	 */
	public const STATE_AI_ENABLED = 'ai_enabled';

	/**
	 * Priority SMS enabled.
	 */
	public const STATE_SMS_ENABLED = 'sms_enabled';

	/**
	 * Managed uploads enabled.
	 */
	public const STATE_MANAGED_UPLOADS_ENABLED = 'managed_uploads_enabled';

	/**
	 * Payments (Stripe Connect) enabled.
	 */
	public const STATE_PAYMENTS_ENABLED = 'payments_enabled';

	/**
	 * Capability keys (mirror the backend capabilities endpoint).
	 */
	public const CAP_AI       = 'semantic_classification';
	public const CAP_SMS      = 'priority_sms';
	public const CAP_UPLOADS  = 'managed_uploads';
	public const CAP_PAYMENTS = 'payments';

	/**
	 * Capability key → highest state it produces.
	 *
	 * @var array<string, string>
	 */
	private const CAPABILITY_STATES = [
		self::CAP_AI       => self::STATE_AI_ENABLED,
		self::CAP_SMS      => self::STATE_SMS_ENABLED,
		self::CAP_UPLOADS  => self::STATE_MANAGED_UPLOADS_ENABLED,
		self::CAP_PAYMENTS => self::STATE_PAYMENTS_ENABLED,
	];

	/**
	 * Bind the site key to a Cloud account after a successful
	 * /v1/sites/connect call.
	 *
	 * @param array<string, mixed> $capabilities Server-reported capabilities.
	 * @param string|null          $dashboardUrl Dashboard deep link reported by the server (D8).
	 * @param array<string, mixed> $account      Sign-in identity reported by the server (email/phone).
	 */
	public function connect( string $siteId, string $siteUrl, string $siteName, array $capabilities = [], ?string $dashboardUrl = null, array $account = [] ): void {
		$this->save(
			[
				'state'         => self::STATE_ACCOUNT_CONNECTED,
				'site_id'       => $siteId,
				'site_url'      => $siteUrl,
				'site_name'     => $siteName,
				'connected_at'  => current_time( 'mysql', true ),
				'enabled'       => [],
				'capabilities'  => $capabilities,
				'subscription'  => [],
				'usage'         => [],
				'dashboard_url' => $dashboardUrl,
				'account'       => self::normalizeAccount( $account ),
			]
		);
	}

	/**
	 * Forget the Cloud binding. Core keeps working locally (v2 §8).
	 */
	public function disconnect(): void {
		$this->save( [ 'state' => self::STATE_DISCONNECTED ] );
	}

	/**
	 * Current consent state.
	 */
	public function state(): string {
		$data = $this->get();

		return (string) ( $data['state'] ?? self::STATE_DISCONNECTED );
	}

	/**
	 * Whether the given capability was explicitly enabled.
	 */
	public function capabilityEnabled( string $capability ): bool {
		$data = $this->get();

		return in_array( $capability, (array) ( $data['enabled'] ?? [] ), true );
	}

	/**
	 * Explicitly enable a capability (requires a connected account).
	 * Capabilities stack: enabling SMS on top of AI is allowed.
	 */
	public function enableCapability( string $capability ): bool {
		if ( ! isset( self::CAPABILITY_STATES[ $capability ] ) ) {
			return false;
		}

		$data = $this->get();

		if ( self::STATE_DISCONNECTED === (string) ( $data['state'] ?? self::STATE_DISCONNECTED ) ) {
			return false;
		}

		$enabled = array_values( array_unique( array_merge( (array) ( $data['enabled'] ?? [] ), [ $capability ] ) ) );
		$data    = array_merge(
			$data,
			[
				'enabled' => $enabled,
				'state'   => self::CAPABILITY_STATES[ $capability ],
			]
		);

		$this->save( $data );

		return true;
	}

	/**
	 * Enable a capability only when the site can actually use it
	 * (configured service + connected account). Returns true when it was
	 * newly enabled. Never disables — unchecking a form must not revoke
	 * consent other forms rely on.
	 *
	 * @param string $capability Capability key.
	 */
	public function grantCapability( string $capability ): bool {
		if ( ! CloudConfig::isConfigured() || self::STATE_DISCONNECTED === $this->state() ) {
			return false;
		}

		return ! $this->capabilityEnabled( $capability ) && $this->enableCapability( $capability );
	}

	/**
	 * Explicitly disable a capability and recompute the consent state.
	 */
	public function disableCapability( string $capability ): bool {
		if ( ! isset( self::CAPABILITY_STATES[ $capability ] ) ) {
			return false;
		}

		$data = $this->get();

		if ( ! $this->capabilityEnabled( $capability ) ) {
			return true;
		}

		$enabled = array_values(
			array_filter(
				(array) ( $data['enabled'] ?? [] ),
				static fn ( $item ): bool => $item !== $capability
			)
		);

		$state = self::STATE_DISCONNECTED;

		if ( '' !== (string) ( $data['site_id'] ?? '' ) ) {
			$state = self::STATE_ACCOUNT_CONNECTED;
		}

		foreach ( array_reverse( self::CAPABILITY_STATES ) as $cap => $capabilityState ) {
			if ( in_array( $cap, $enabled, true ) ) {
				$state = $capabilityState;
				break;
			}
		}

		$data = array_merge(
			$data,
			[
				'enabled' => $enabled,
				'state'   => $state,
			]
		);

		$this->save( $data );

		return true;
	}

	/**
	 * Bound site id, if connected.
	 */
	public function siteId(): ?string {
		$data = $this->get();

		return '' !== (string) ( $data['site_id'] ?? '' ) ? (string) $data['site_id'] : null;
	}

	/**
	 * Dashboard deep link for this site, if the server reported one.
	 * Billing actions live in the dashboard (D8).
	 */
	public function dashboardUrl(): ?string {
		$data = $this->get();
		$url  = (string) ( $data['dashboard_url'] ?? '' );

		return '' !== $url ? $url : null;
	}

	/**
	 * Server-reported capabilities, if connected.
	 *
	 * @return array<string, mixed>
	 */
	public function serverCapabilities(): array {
		$data = $this->get();

		return is_array( $data['capabilities'] ?? null ) ? $data['capabilities'] : [];
	}

	/**
	 * Refresh capabilities reported by the server.
	 *
	 * @param array<string, mixed> $capabilities Server-reported capabilities.
	 */
	public function updateServerCapabilities( array $capabilities ): void {
		$data = $this->get();

		if ( self::STATE_DISCONNECTED === (string) ( $data['state'] ?? self::STATE_DISCONNECTED ) ) {
			return;
		}

		$this->save( array_merge( $data, [ 'capabilities' => $capabilities ] ) );
	}

	/**
	 * Subscription summary reported by the server (Phase 8).
	 *
	 * @return array<string, mixed>
	 */
	public function subscription(): array {
		$data = $this->get();

		return is_array( $data['subscription'] ?? null ) ? $data['subscription'] : [];
	}

	/**
	 * Store the subscription summary reported by the server.
	 *
	 * @param array<string, mixed> $subscription Summary payload.
	 */
	public function updateSubscription( array $subscription ): void {
		$data = $this->get();

		if ( self::STATE_DISCONNECTED === (string) ( $data['state'] ?? self::STATE_DISCONNECTED ) ) {
			return;
		}

		$this->save( array_merge( $data, [ 'subscription' => $subscription ] ) );
	}

	/**
	 * Human label of the subscription discount (e.g. "20% off",
	 * "20% off for 3 month(s)", "USD 10.00 off") reported by the server;
	 * null when no discount is active.
	 */
	public function discountLabel(): ?string {
		$discount = $this->subscription()['discount'] ?? null;

		if ( ! is_array( $discount ) ) {
			return null;
		}

		$coupon = is_array( $discount['coupon'] ?? null ) ? $discount['coupon'] : [];

		if ( ! empty( $coupon['percent_off'] ) ) {
			$percent = rtrim( rtrim( number_format( (float) $coupon['percent_off'], 2, '.', '' ), '0' ), '.' );
			$label   = $percent . '% off';
		} elseif ( ! empty( $coupon['amount_off'] ) ) {
			$label = strtoupper( (string) ( $coupon['currency'] ?? '' ) ) . ' ' . number_format( ( (int) $coupon['amount_off'] ) / 100, 2 ) . ' off';
		} else {
			return null;
		}

		if ( 'repeating' === ( $coupon['duration'] ?? '' ) && ! empty( $coupon['duration_in_months'] ) ) {
			$label .= ' ' . sprintf(
				/* translators: %d: number of covered months */
				__( 'for %d month(s)', 'relevant-forms' ),
				(int) $coupon['duration_in_months']
			);
		}

		return $label;
	}

	/**
	 * Whether the site has operator-issued complimentary access.
	 */
	public function isComplimentary(): bool {
		$complimentary = $this->subscription()['complimentary'] ?? null;

		return is_array( $complimentary ) && ! empty( $complimentary['active'] );
	}

	/**
	 * Complimentary access end date as reported by the server; null when
	 * the access is permanent or not complimentary.
	 */
	public function complimentaryUntil(): ?string {
		$complimentary = $this->subscription()['complimentary'] ?? null;

		if ( ! is_array( $complimentary ) || empty( $complimentary['active'] ) || empty( $complimentary['until'] ) ) {
			return null;
		}

		return (string) $complimentary['until'];
	}

	/**
	 * Account identity (the email / phone the client signs in with)
	 * reported by the server; empty strings when unknown or account-less.
	 *
	 * @return array{email: string, phone: string}
	 */
	public function account(): array {
		$data = $this->get();

		return self::normalizeAccount( is_array( $data['account'] ?? null ) ? $data['account'] : [] );
	}

	/**
	 * Refresh the account identity reported by the server.
	 *
	 * @param array<string, mixed> $account Identity payload.
	 */
	public function updateAccount( array $account ): void {
		$data = $this->get();

		if ( self::STATE_DISCONNECTED === (string) ( $data['state'] ?? self::STATE_DISCONNECTED ) ) {
			return;
		}

		$this->save( array_merge( $data, [ 'account' => self::normalizeAccount( $account ) ] ) );
	}

	/**
	 * Normalize a server identity payload to the stored shape.
	 *
	 * @param array<string, mixed> $account Raw identity payload.
	 * @return array{email: string, phone: string}
	 */
	private static function normalizeAccount( array $account ): array {
		return [
			'email' => (string) ( $account['email'] ?? '' ),
			'phone' => (string) ( $account['phone'] ?? '' ),
		];
	}

	/**
	 * Payments (Stripe Connect) status reported by the server, if any.
	 *
	 * @return array<string, mixed>
	 */
	public function payments(): array {
		$data = $this->get();

		return is_array( $data['payments'] ?? null ) ? $data['payments'] : [];
	}

	/**
	 * Store the payments status reported by the server.
	 *
	 * @param array<string, mixed> $payments Status payload.
	 */
	public function updatePayments( array $payments ): void {
		$data = $this->get();

		if ( self::STATE_DISCONNECTED === (string) ( $data['state'] ?? self::STATE_DISCONNECTED ) ) {
			return;
		}

		$this->save( array_merge( $data, [ 'payments' => $payments ] ) );
	}

	/**
	 * Cached usage data, if any.
	 *
	 * @return array<string, mixed>
	 */
	public function usage(): array {
		$data = $this->get();

		return is_array( $data['usage'] ?? null ) ? $data['usage'] : [];
	}

	/**
	 * Store the latest usage payload.
	 *
	 * @param array<string, mixed> $usage Usage payload.
	 */
	public function updateUsage( array $usage ): void {
		$data = $this->get();

		if ( self::STATE_DISCONNECTED === (string) ( $data['state'] ?? self::STATE_DISCONNECTED ) ) {
			return;
		}

		$this->save( array_merge( $data, [ 'usage' => $usage ] ) );
	}

	/**
	 * Raw stored data.
	 *
	 * @return array<string, mixed>
	 */
	private function get(): array {
		$data = get_option( self::OPTION, [] );

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Persist the connection data.
	 *
	 * @param array<string, mixed> $data Connection state.
	 */
	private function save( array $data ): void {
		update_option( self::OPTION, $data );

		do_action( 'relevant_forms_cloud_state_changed', $data['state'] ?? self::STATE_DISCONNECTED );
	}
}
