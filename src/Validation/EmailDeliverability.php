<?php
/**
 * Email deliverability checks: MX records and mail server reachability.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Validation;

use RelevantForms\Support\Logger;

/**
 * Checks an email address beyond its syntax.
 *
 * Two questions are asked, in order:
 *
 * 1. does the domain accept mail at all — MX records, or the A/AAAA record
 *    RFC 5321 falls back to (and an RFC 7505 "Null MX" counts as a no)?
 * 2. when the site asks for it, does the mail server answer on port 25?
 *
 * The design is deliberately conservative. Only the first answer can reject a
 * submission, because it is definitive. Everything else — missing DNS
 * extensions, a resolver failure, a blocked outbound port 25, a greylisting
 * mail server — is reported as inconclusive and **never** blocks a real
 * visitor: the cost of turning away a legitimate signup is far higher than
 * the cost of storing one address that bounces.
 *
 * Answers are cached per domain (6 hours for DNS, 1 hour for reachability), so
 * a form that is submitted repeatedly is checked at most once.
 */
final class EmailDeliverability {

	/**
	 * Validation disabled.
	 */
	public const MODE_OFF = 'off';

	/**
	 * Syntax plus MX records (or the A/AAAA fallback).
	 */
	public const MODE_MX = 'mx';

	/**
	 * MX records plus a port 25 reachability probe.
	 */
	public const MODE_MX_SMTP = 'mx_smtp';

	/**
	 * Settings option holding the mode.
	 */
	public const OPTION = 'relevant_forms_settings';

	/**
	 * Cache key prefix.
	 */
	private const CACHE_PREFIX = 'relevant_forms_email_';

	/**
	 * Option holding the targets that have a cached answer.
	 *
	 * WordPress cannot enumerate transients, so a bounded index of what was
	 * cached is kept to make "clear cached checks" possible.
	 */
	private const INDEX_OPTION = 'relevant_forms_email_check_cache';

	/**
	 * Most targets the index keeps.
	 */
	private const INDEX_LIMIT = 500;

	/**
	 * DNS answers are cached for six hours.
	 */
	private const MX_TTL = 21600;

	/**
	 * Reachability answers are cached for one hour.
	 */
	private const PROBE_TTL = 3600;

	/**
	 * Seconds to wait for a connection and the SMTP banner.
	 */
	private const PROBE_TIMEOUT = 3;

	/**
	 * Port a receiving mail server listens on.
	 */
	public const SMTP_PORT = 25;

	/**
	 * Allowed modes.
	 *
	 * @var array<int, string>
	 */
	private const MODES = [ self::MODE_OFF, self::MODE_MX, self::MODE_MX_SMTP ];

	/**
	 * The modes the settings screen offers.
	 *
	 * @return array<int, string>
	 */
	public static function modes(): array {
		return self::MODES;
	}

	/**
	 * The configured mode.
	 *
	 * Off unless the site turned it on: switching a live form to rejecting
	 * addresses is a decision for the site owner, not an upgrade side effect.
	 */
	public static function mode(): string {
		$settings = (array) get_option( self::OPTION, [] );
		$mode     = (string) ( $settings['email_check_mode'] ?? self::MODE_OFF );

		return in_array( $mode, self::MODES, true ) ? $mode : self::MODE_OFF;
	}

	/**
	 * Whether the address of a submission should be checked at all.
	 */
	public static function enabled(): bool {
		return self::MODE_OFF !== self::mode();
	}

	/**
	 * Whether the mail server reachability probe runs.
	 */
	public static function probesSmtp(): bool {
		return self::MODE_MX_SMTP === self::mode();
	}

	/**
	 * Whether an unreachable mail server counts as invalid.
	 *
	 * Off by default: an outbound port 25 is blocked on many hosts, so a
	 * failed probe says as much about the host as about the address.
	 */
	public static function rejectsUnreachable(): bool {
		if ( ! self::probesSmtp() ) {
			return false;
		}

		$settings = (array) get_option( self::OPTION, [] );

		return ! empty( $settings['email_check_reject_unreachable'] );
	}

	/**
	 * Check an address.
	 *
	 * @param string $email Address to check.
	 */
	public static function check( string $email ): EmailCheckResult {
		$email  = trim( $email );
		$domain = self::domain( $email );

		if ( ! self::enabled() || '' === $domain ) {
			return EmailCheckResult::ok( $domain );
		}

		if ( ! function_exists( 'dns_get_record' ) && ! function_exists( 'checkdnsrr' ) ) {
			Logger::warning( 'Email domain check skipped: no DNS function is available', [ 'domain' => $domain ] );

			return self::finalize( EmailCheckResult::unknown( $domain ), $email );
		}

		[ $state, $hosts ] = self::resolve( $domain );

		if ( 'unknown' === $state ) {
			Logger::warning( 'Email domain check inconclusive', [ 'domain' => $domain ] );

			return self::finalize( EmailCheckResult::unknown( $domain ), $email );
		}

		if ( 'none' === $state ) {
			return self::finalize( EmailCheckResult::noMx( $domain ), $email );
		}

		if ( ! self::probesSmtp() ) {
			return self::finalize( EmailCheckResult::ok( $domain ), $email );
		}

		$host      = $hosts[0] ?? $domain;
		$reachable = self::reachable( $host );

		if ( true === $reachable ) {
			return self::finalize( EmailCheckResult::ok( $domain ), $email );
		}

		// A failed probe is only ever a hint: the cache key is the domain, not
		// the mailbox, so it cannot say anything about the individual address.
		Logger::warning(
			'Mail server did not answer on port 25',
			[
				'domain' => $domain,
				'host'   => $host,
				'port'   => self::SMTP_PORT,
			]
		);

		return self::finalize( EmailCheckResult::unreachable( $domain ), $email );
	}

	/**
	 * The domain of an address, or '' when it cannot be checked.
	 *
	 * Domain literals (`user@[192.0.2.1]`) and single-label hosts are skipped:
	 * they are either private or resolvable only through a hosts file.
	 *
	 * @param string $email Address.
	 */
	public static function domain( string $email ): string {
		$at = strrpos( $email, '@' );

		if ( false === $at || $at === strlen( $email ) - 1 ) {
			return '';
		}

		$domain = strtolower( rtrim( trim( substr( $email, $at + 1 ) ), '.' ) );

		if ( '' === $domain
			|| str_contains( $domain, '[' )
			|| str_contains( $domain, ']' )
			|| str_contains( $domain, ':' )
			|| ! str_contains( $domain, '.' )
		) {
			return '';
		}

		return $domain;
	}

	/**
	 * Forget cached answers.
	 *
	 * @param string $domain Domain to forget, or '' for everything.
	 */
	public static function flush( string $domain = '' ): void {
		if ( '' !== $domain ) {
			self::forgetDomain( $domain );

			return;
		}

		foreach ( array_keys( (array) get_option( self::INDEX_OPTION, [] ) ) as $key ) {
			$key = (string) $key;

			if ( ! str_contains( $key, ':' ) ) {
				continue;
			}

			[ $kind, $target ] = explode( ':', $key, 2 );

			delete_transient( self::cacheKey( $kind, $target ) );
		}

		delete_option( self::INDEX_OPTION );
	}

	/**
	 * How many answers are cached, for the settings and diagnostics screens.
	 */
	public static function cachedCount(): int {
		return count( (array) get_option( self::INDEX_OPTION, [] ) );
	}

	/**
	 * Forget one domain: its DNS answer and the probes of its mail hosts.
	 *
	 * @param string $domain Domain.
	 */
	private static function forgetDomain( string $domain ): void {
		$cached = get_transient( self::cacheKey( 'mx', $domain ) );
		$hosts  = is_array( $cached ) ? array_map( 'strval', (array) ( $cached['hosts'] ?? [] ) ) : [];

		foreach ( array_merge( [ $domain ], $hosts ) as $host ) {
			delete_transient( self::cacheKey( 'smtp', (string) $host ) );
		}

		delete_transient( self::cacheKey( 'mx', $domain ) );

		$index = (array) get_option( self::INDEX_OPTION, [] );

		unset( $index[ 'mx:' . $domain ] );

		foreach ( $hosts as $host ) {
			unset( $index[ 'smtp:' . (string) $host ] );
		}

		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * What the check can actually do on this install.
	 *
	 * Surfaced on the settings and diagnostics screens: the answer depends on
	 * the host (DNS functions can be disabled) and the network (port 25 is
	 * commonly blocked outbound).
	 *
	 * @return array{mode: string, dns: bool, simple_dns: bool, sockets: bool, reject_unreachable: bool}
	 */
	public static function capabilities(): array {
		return [
			'mode'               => self::mode(),
			'dns'                => function_exists( 'dns_get_record' ),
			'simple_dns'         => function_exists( 'checkdnsrr' ),
			'sockets'            => function_exists( 'stream_socket_client' ),
			'reject_unreachable' => self::rejectsUnreachable(),
		];
	}

	/**
	 * Resolve the mail servers of a domain.
	 *
	 * @param string $domain Domain.
	 * @return array{0: string, 1: array<int, string>} State (`found`, `none`
	 *                                                 or `unknown`) and hosts
	 *                                                 in preference order.
	 */
	private static function resolve( string $domain ): array {
		$cached = get_transient( self::cacheKey( 'mx', $domain ) );

		if ( is_array( $cached ) && isset( $cached['state'] ) && is_string( $cached['state'] ) ) {
			return [ $cached['state'], array_values( array_filter( (array) ( $cached['hosts'] ?? [] ), 'is_string' ) ) ];
		}

		$mx = self::records( $domain, 'MX' );

		if ( null === $mx ) {
			return [ 'unknown', [] ];
		}

		$hosts = self::mxHosts( $mx );

		if ( [] !== $hosts ) {
			return self::remember( $domain, 'found', $hosts );
		}

		/*
		 * A "Null MX" (RFC 7505) is a published, deliberate "this domain does
		 * not accept mail": dns_get_record() reports it as an MX with an empty
		 * target. It is a definitive answer, so it must not fall through to the
		 * A/AAAA fallback below.
		 */
		if ( [] !== $mx ) {
			return self::remember( $domain, 'none', [] );
		}

		$hasAddress = [] !== (array) self::records( $domain, 'A' ) || [] !== (array) self::records( $domain, 'AAAA' );

		if ( ! $hasAddress ) {
			// checkdnsrr() answers yes/no only, so a "no" from it cannot be told
			// apart from a failed lookup: stay inconclusive rather than block.
			return self::remember( $domain, self::resolverIsAuthoritative() ? 'none' : 'unknown', [] );
		}

		// RFC 5321: without MX records the domain's own address is the mail host.
		return self::remember( $domain, 'found', [ $domain ] );
	}

	/**
	 * Store an answer and return it.
	 *
	 * @param string             $domain Domain.
	 * @param string             $state  State.
	 * @param array<int, string> $hosts  Mail hosts.
	 * @return array{0: string, 1: array<int, string>}
	 */
	private static function remember( string $domain, string $state, array $hosts ): array {
		if ( 'unknown' !== $state ) {
			set_transient(
				self::cacheKey( 'mx', $domain ),
				[
					'state' => $state,
					'hosts' => $hosts,
				],
				self::MX_TTL
			);

			self::index( 'mx', $domain );
		}

		return [ $state, $hosts ];
	}

	/**
	 * Mail hosts of an MX answer, in preference order.
	 *
	 * @param array<int, mixed> $records MX records.
	 * @return array<int, string>
	 */
	private static function mxHosts( array $records ): array {
		$hosts = [];

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$target = strtolower( rtrim( (string) ( $record['target'] ?? '' ), '.' ) );

			if ( '' === $target ) {
				continue;
			}

			$hosts[ $target ] = (int) ( $record['pri'] ?? 0 );
		}

		asort( $hosts, SORT_NUMERIC );

		return array_keys( $hosts );
	}

	/**
	 * Query the resolver.
	 *
	 * @param string $domain Domain.
	 * @param string $type   MX, A or AAAA.
	 * @return array<int, mixed>|null Records, or null when the lookup failed.
	 */
	private static function records( string $domain, string $type ): ?array {
		/**
		 * Short-circuit a DNS lookup.
		 *
		 * Returning an array — including an empty one, which means "this domain
		 * publishes no record of that type" — answers the query without touching
		 * the resolver. Returning false reports a failed lookup, and null falls
		 * through to real DNS. Used by tests and by integrations that maintain
		 * their own domain data.
		 *
		 * @param array<int, mixed>|false|null $records Injected answer.
		 * @param string                       $domain  Domain being resolved.
		 * @param string                       $type    MX, A or AAAA.
		 */
		$injected = apply_filters( 'relevant_forms_email_dns_records', null, $domain, $type );

		if ( is_array( $injected ) ) {
			return $injected;
		}

		if ( false === $injected ) {
			return null;
		}

		if ( ! function_exists( 'dns_get_record' ) ) {
			return function_exists( 'checkdnsrr' ) && checkdnsrr( $domain, $type ) ? [ [ 'type' => $type ] ] : [];
		}

		$flags = match ( $type ) {
			'MX'    => DNS_MX,
			'A'     => DNS_A,
			'AAAA'  => DNS_AAAA,
			default => 0,
		};

		if ( 0 === $flags ) {
			return null;
		}

		$answer = dns_get_record( $domain, $flags );

		// false is a failed query (timeout, SERVFAIL); an empty array is a
		// valid "this domain publishes no record of that type".
		return false === $answer ? null : $answer;
	}

	/**
	 * Whether the active resolver can tell "no records" from "lookup failed".
	 */
	private static function resolverIsAuthoritative(): bool {
		return function_exists( 'dns_get_record' );
	}


	/**
	 * Probe the mail server of a host, cached.
	 *
	 * @param string $host Mail host.
	 * @return bool|null Whether it answered, or null when no probe is possible.
	 */
	private static function reachable( string $host ): ?bool {
		$cached = get_transient( self::cacheKey( 'smtp', $host ) );

		if ( 'yes' === $cached ) {
			return true;
		}

		if ( 'no' === $cached ) {
			return false;
		}

		/**
		 * Short-circuit the reachability probe.
		 *
		 * Returning a boolean answers without opening a socket; null falls
		 * through to a real connection attempt.
		 *
		 * @param bool|null $reachable Injected answer.
		 * @param string    $host      Mail host.
		 * @param int       $port      Port that was probed.
		 */
		$injected = apply_filters( 'relevant_forms_email_smtp_probe', null, $host, self::SMTP_PORT );

		if ( is_bool( $injected ) ) {
			return $injected;
		}

		if ( ! function_exists( 'stream_socket_client' ) ) {
			return null;
		}

		$reachable = self::probe( $host );

		set_transient( self::cacheKey( 'smtp', $host ), $reachable ? 'yes' : 'no', self::PROBE_TTL );
		self::index( 'smtp', $host );

		return $reachable;
	}

	/**
	 * Open a connection to a mail server and read its banner.
	 *
	 * A socket that answers with a 2xx greeting is a receiving mail server. The
	 * probe stops there: issuing MAIL FROM/RCPT TO would be sender verification,
	 * which catch-all and greylisting servers answer unreliably, and some hosts
	 * treat it as a directory harvest attempt.
	 *
	 * @param string $host Mail host.
	 */
	private static function probe( string $host ): bool {
		$errno  = 0;
		$error  = '';
		$socket = stream_socket_client(
			sprintf( 'tcp://%s:%d', $host, self::SMTP_PORT ),
			$errno,
			$error,
			self::PROBE_TIMEOUT
		);

		if ( false === $socket ) {
			return false;
		}

		stream_set_timeout( $socket, self::PROBE_TIMEOUT );

		$banner = fgets( $socket, 512 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a socket, not a file.
		fwrite( $socket, "QUIT\r\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a socket, not a file.
		fclose( $socket );

		return is_string( $banner ) && str_starts_with( $banner, '2' );
	}

	/**
	 * Apply the extension filter and the site policy to a verdict.
	 *
	 * @param EmailCheckResult $result Verdict.
	 * @param string           $email  Address that was checked.
	 */
	private static function finalize( EmailCheckResult $result, string $email ): EmailCheckResult {
		/**
		 * Filter the deliverability verdict.
		 *
		 * Returning anything other than an EmailCheckResult keeps the original
		 * verdict, so a broken integration cannot change an outcome.
		 *
		 * @param EmailCheckResult $result Verdict.
		 * @param string           $email  Address that was checked.
		 */
		$filtered = apply_filters( 'relevant_forms_email_check_result', $result, $email );

		if ( $filtered instanceof EmailCheckResult ) {
			$result = $filtered;
		}

		return $result->withRejectUnreachable( self::rejectsUnreachable() );
	}

	/**
	 * Record a cached target so it can be flushed later.
	 *
	 * @param string $kind   `mx` or `smtp`.
	 * @param string $target Domain or host.
	 */
	private static function index( string $kind, string $target ): void {
		$index = (array) get_option( self::INDEX_OPTION, [] );
		$key   = $kind . ':' . $target;

		if ( isset( $index[ $key ] ) ) {
			return;
		}

		if ( count( $index ) >= self::INDEX_LIMIT ) {
			// Oldest first: insertion order is preserved, so drop the head.
			array_shift( $index );
		}

		$index[ $key ] = time();

		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Cache key for a domain or host.
	 *
	 * @param string $kind   `mx` or `smtp`.
	 * @param string $target Domain or host.
	 */
	private static function cacheKey( string $kind, string $target ): string {
		return self::CACHE_PREFIX . $kind . '_' . md5( $target );
	}
}
