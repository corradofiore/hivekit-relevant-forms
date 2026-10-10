<?php
/**
 * Outcome of an email deliverability check.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Validation;

/**
 * Immutable result of {@see EmailDeliverability::check()}.
 *
 * Only `no_mx` is a definitive answer: the domain publishes neither MX records
 * nor an A/AAAA address, so the address can never receive mail. Every other
 * failure is inconclusive and is reported without blocking (see the class
 * docblock of the checker for why).
 */
final class EmailCheckResult {

	/**
	 * The address looks deliverable, or the check could not decide.
	 */
	public const STATUS_OK = 'ok';

	/**
	 * The domain cannot receive mail at all.
	 */
	public const STATUS_NO_MX = 'no_mx';

	/**
	 * The domain has a mail server, but it did not answer on port 25.
	 */
	public const STATUS_UNREACHABLE = 'unreachable';

	/**
	 * The check could not run (no DNS functions, resolver failure).
	 */
	public const STATUS_UNKNOWN = 'unknown';

	/**
	 * Whether an unreachable mail server may be treated as invalid.
	 *
	 * Set by the caller from the site settings, so a blocked outbound port 25
	 * stays harmless unless an administrator explicitly accepts the risk.
	 *
	 * @var bool
	 */
	private bool $reject_unreachable = false;

	/**
	 * Constructor.
	 *
	 * @param string $status  One of the STATUS_* constants.
	 * @param string $message Human readable explanation (empty when ok).
	 * @param string $domain  Domain the check ran against.
	 */
	private function __construct(
		private string $status,
		private string $message,
		private string $domain = ''
	) {
	}

	/**
	 * The address passed, or the check was inconclusive.
	 *
	 * @param string $domain Domain the check ran against.
	 */
	public static function ok( string $domain = '' ): self {
		return new self( self::STATUS_OK, '', $domain );
	}

	/**
	 * The domain publishes no mail server at all.
	 *
	 * @param string $domain Domain the check ran against.
	 */
	public static function noMx( string $domain = '' ): self {
		return new self(
			self::STATUS_NO_MX,
			__( 'We could not find a mail server for this email domain. Please check the address.', 'relevant-forms' ),
			$domain
		);
	}

	/**
	 * The domain has a mail server that did not answer.
	 *
	 * @param string $domain Domain the check ran against.
	 */
	public static function unreachable( string $domain = '' ): self {
		return new self(
			self::STATUS_UNREACHABLE,
			__( 'The mail server for this email domain did not respond. Please check the address.', 'relevant-forms' ),
			$domain
		);
	}

	/**
	 * The check could not run.
	 *
	 * @param string $domain Domain the check ran against.
	 */
	public static function unknown( string $domain = '' ): self {
		return new self( self::STATUS_UNKNOWN, '', $domain );
	}

	/**
	 * Whether an unreachable mail server may be treated as invalid.
	 *
	 * @param bool $reject True when the site rejects unreachable domains.
	 */
	public function withRejectUnreachable( bool $reject ): self {
		$this->reject_unreachable = $reject;

		return $this;
	}

	/**
	 * The status constant.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Human readable explanation.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Domain the check ran against.
	 */
	public function domain(): string {
		return $this->domain;
	}

	/**
	 * Whether the submission must be rejected.
	 *
	 * `no_mx` always blocks; `unreachable` only blocks when the site opted in.
	 */
	public function isBlocking(): bool {
		if ( self::STATUS_NO_MX === $this->status ) {
			return true;
		}

		return self::STATUS_UNREACHABLE === $this->status && $this->reject_unreachable;
	}

	/**
	 * Whether the visitor should be warned without blocking.
	 *
	 * Used by the real-time endpoint: the form shows the hint while still
	 * letting the visitor submit, because the server would accept it too.
	 */
	public function isWarning(): bool {
		return self::STATUS_UNREACHABLE === $this->status;
	}

	/**
	 * Payload for the real-time endpoint and the diagnostics screen.
	 *
	 * @return array{status: string, message: string, domain: string, blocking: bool, warning: bool}
	 */
	public function toArray(): array {
		return [
			'status'   => $this->status,
			'message'  => $this->message,
			'domain'   => $this->domain,
			'blocking' => $this->isBlocking(),
			'warning'  => $this->isWarning(),
		];
	}
}
