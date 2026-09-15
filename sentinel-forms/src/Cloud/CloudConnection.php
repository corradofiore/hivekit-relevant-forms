<?php
/**
 * Cloud connection and consent state machine (v2 §8).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Cloud;

/**
 * Tracks the explicit Cloud consent state. Core never transmits form
 * content to Cloud capabilities that are not explicitly enabled.
 */
final class CloudConnection {

	/**
	 * Option key.
	 */
	private const OPTION = 'sentinel_cloud_connection';

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
	 * Capability keys (mirror the backend capabilities endpoint).
	 */
	public const CAP_AI      = 'semantic_classification';
	public const CAP_SMS     = 'priority_sms';
	public const CAP_UPLOADS = 'managed_uploads';

	/**
	 * Capability key → highest state it produces.
	 *
	 * @var array<string, string>
	 */
	private const CAPABILITY_STATES = [
		self::CAP_AI      => self::STATE_AI_ENABLED,
		self::CAP_SMS     => self::STATE_SMS_ENABLED,
		self::CAP_UPLOADS => self::STATE_MANAGED_UPLOADS_ENABLED,
	];

	/**
	 * Bind the site key to a Cloud account after a successful
	 * /v1/sites/connect call.
	 *
	 * @param array<string, mixed> $capabilities Server-reported capabilities.
	 */
	public function connect( string $siteId, string $siteUrl, string $siteName, array $capabilities = [] ): void {
		$this->save(
			[
				'state'        => self::STATE_ACCOUNT_CONNECTED,
				'site_id'      => $siteId,
				'site_url'     => $siteUrl,
				'site_name'    => $siteName,
				'connected_at' => current_time( 'mysql', true ),
				'enabled'      => [],
				'capabilities' => $capabilities,
				'subscription' => [],
				'usage'        => [],
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

		do_action( 'sentinel_forms_cloud_state_changed', $data['state'] ?? self::STATE_DISCONNECTED );
	}
}
