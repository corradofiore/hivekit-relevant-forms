<?php
/**
 * Email deliverability unit tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use RelevantForms\Validation\EmailCheckResult;
use RelevantForms\Validation\EmailDeliverability;

/*
|--------------------------------------------------------------------------
| DNS and SMTP seams
|--------------------------------------------------------------------------
|
| The checker asks the resolver and the network two questions. Both are
| answered here through the filters the checker publishes, so no test touches
| real DNS or opens a socket. An entry keyed "<domain>:<type>" (or a host for
| SMTP) answers it; a missing entry means "the real lookup", and false means
| "the lookup failed".
*/

$dns      = [];
$smtp     = [];
$settings = [ 'email_check_mode' => EmailDeliverability::MODE_MX ];

beforeEach(
	function () use ( &$dns, &$smtp, &$settings ) {
		$dns      = [];
		$smtp     = [];
		$settings = [ 'email_check_mode' => EmailDeliverability::MODE_MX ];

		Functions\when( 'get_option' )->alias(
			static function ( string $name, mixed $fallback = false ) use ( &$settings ): mixed {
				return 'relevant_forms_settings' === $name ? $settings : $fallback;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value, ...$args ) use ( &$dns, &$smtp ): mixed {
				if ( 'relevant_forms_email_dns_records' === $tag ) {
					return $dns[ $args[0] . ':' . $args[1] ] ?? null;
				}

				if ( 'relevant_forms_email_smtp_probe' === $tag ) {
					return $smtp[ $args[0] ] ?? null;
				}

				return $value;
			}
		);
	}
);

/*
|--------------------------------------------------------------------------
| Domain extraction
|--------------------------------------------------------------------------
*/

it(
	'reads the domain of an address',
	function () {
		expect( EmailDeliverability::domain( 'user@EXAMPLE.com' ) )->toBe( 'example.com' );
		expect( EmailDeliverability::domain( 'user@example.com.' ) )->toBe( 'example.com' );
		expect( EmailDeliverability::domain( 'a.b@sub.example.co.uk' ) )->toBe( 'sub.example.co.uk' );
	}
);

it(
	'ignores addresses that cannot be resolved',
	function () {
		expect( EmailDeliverability::domain( 'user@[192.0.2.1]' ) )->toBe( '' );
		expect( EmailDeliverability::domain( 'user@localhost' ) )->toBe( '' );
		expect( EmailDeliverability::domain( 'user@' ) )->toBe( '' );
		expect( EmailDeliverability::domain( 'user' ) )->toBe( '' );
	}
);

/*
|--------------------------------------------------------------------------
| Domain checks
|--------------------------------------------------------------------------
*/

it(
	'accepts a domain that publishes MX records',
	function () use ( &$dns ) {
		$dns['example.com:MX'] = [
			[
				'target' => 'mx-b.example.com',
				'pri'    => 20,
			],
			[
				'target' => 'mx-a.example.com',
				'pri'    => 10,
			],
		];

		$result = EmailDeliverability::check( 'user@example.com' );

		expect( $result->status() )->toBe( EmailCheckResult::STATUS_OK );
		expect( $result->isBlocking() )->toBeFalse();
	}
);

it(
	'accepts a domain whose only mail record is an address record',
	function () use ( &$dns ) {
		$dns['example.com:MX'] = [];
		$dns['example.com:A']  = [ [ 'ip' => '192.0.2.10' ] ];

		$result = EmailDeliverability::check( 'user@example.com' );

		expect( $result->status() )->toBe( EmailCheckResult::STATUS_OK );
	}
);

it(
	'rejects a domain that publishes neither MX nor an address record',
	function () use ( &$dns ) {
		$dns['nowhere.example:MX']   = [];
		$dns['nowhere.example:A']    = [];
		$dns['nowhere.example:AAAA'] = [];

		$result = EmailDeliverability::check( 'user@nowhere.example' );

		expect( $result->status() )->toBe( EmailCheckResult::STATUS_NO_MX );
		expect( $result->isBlocking() )->toBeTrue();
		expect( $result->message() )->not->toBe( '' );
	}
);

it(
	'rejects a domain with a null MX record',
	function () use ( &$dns ) {
		// RFC 7505: a single MX with an empty target means "no mail here".
		$dns['example.com:MX'] = [
			[
				'target' => '',
				'pri'    => 0,
			],
		];

		$result = EmailDeliverability::check( 'user@example.com' );

		expect( $result->status() )->toBe( EmailCheckResult::STATUS_NO_MX );
		expect( $result->isBlocking() )->toBeTrue();
	}
);

it(
	'never blocks when the lookup itself fails',
	function () use ( &$dns ) {
		$dns['example.com:MX'] = false;

		$result = EmailDeliverability::check( 'user@example.com' );

		expect( $result->status() )->toBe( EmailCheckResult::STATUS_UNKNOWN );
		expect( $result->isBlocking() )->toBeFalse();
	}
);

it(
	'accepts an address without a checkable domain',
	function () {
		$result = EmailDeliverability::check( 'root@localhost' );

		expect( $result->status() )->toBe( EmailCheckResult::STATUS_OK );
	}
);

/*
|--------------------------------------------------------------------------
| Reachability
|--------------------------------------------------------------------------
*/

it(
	'does not probe the mail server when only the domain is checked',
	function () use ( &$dns, &$smtp ) {
		$dns['example.com:MX']  = [
			[
				'target' => 'mx.example.com',
				'pri'    => 10,
			],
		];
		$smtp['mx.example.com'] = false;

		$result = EmailDeliverability::check( 'user@example.com' );

		expect( $result->status() )->toBe( EmailCheckResult::STATUS_OK );
	}
);

it(
	'reports an unreachable mail server without blocking by default',
	function () use ( &$dns, &$smtp, &$settings ) {
		$settings               = [ 'email_check_mode' => EmailDeliverability::MODE_MX_SMTP ];
		$dns['example.com:MX']  = [
			[
				'target' => 'mx.example.com',
				'pri'    => 10,
			],
		];
		$smtp['mx.example.com'] = false;

		$result = EmailDeliverability::check( 'user@example.com' );

		expect( $result->status() )->toBe( EmailCheckResult::STATUS_UNREACHABLE );
		expect( $result->isWarning() )->toBeTrue();
		expect( $result->isBlocking() )->toBeFalse();
	}
);

it(
	'rejects an unreachable mail server when the site opted in',
	function () use ( &$dns, &$smtp, &$settings ) {
		$settings               = [
			'email_check_mode'               => EmailDeliverability::MODE_MX_SMTP,
			'email_check_reject_unreachable' => 1,
		];
		$dns['example.com:MX']  = [
			[
				'target' => 'mx.example.com',
				'pri'    => 10,
			],
		];
		$smtp['mx.example.com'] = false;

		$result = EmailDeliverability::check( 'user@example.com' );

		expect( $result->status() )->toBe( EmailCheckResult::STATUS_UNREACHABLE );
		expect( $result->isBlocking() )->toBeTrue();
	}
);

it(
	'accepts a reachable mail server',
	function () use ( &$dns, &$smtp, &$settings ) {
		$settings               = [ 'email_check_mode' => EmailDeliverability::MODE_MX_SMTP ];
		$dns['example.com:MX']  = [
			[
				'target' => 'mx.example.com',
				'pri'    => 10,
			],
		];
		$smtp['mx.example.com'] = true;

		$result = EmailDeliverability::check( 'user@example.com' );

		expect( $result->status() )->toBe( EmailCheckResult::STATUS_OK );
	}
);

/*
|--------------------------------------------------------------------------
| Modes and payload
|--------------------------------------------------------------------------
*/

it(
	'resolves the configured mode with a safe fallback',
	function () use ( &$settings ) {
		// Off by default: enabling the check is a deliberate site decision.
		$settings = [];

		expect( EmailDeliverability::mode() )->toBe( EmailDeliverability::MODE_OFF );
		expect( EmailDeliverability::enabled() )->toBeFalse();

		$settings = [ 'email_check_mode' => EmailDeliverability::MODE_MX ];

		expect( EmailDeliverability::enabled() )->toBeTrue();
		expect( EmailDeliverability::probesSmtp() )->toBeFalse();

		$settings = [ 'email_check_mode' => EmailDeliverability::MODE_OFF ];

		expect( EmailDeliverability::enabled() )->toBeFalse();

		$settings = [ 'email_check_mode' => 'nonsense' ];

		expect( EmailDeliverability::mode() )->toBe( EmailDeliverability::MODE_OFF );
	}
);

it(
	'checks nothing when the check is off',
	function () use ( &$dns, &$settings ) {
		$settings                  = [ 'email_check_mode' => EmailDeliverability::MODE_OFF ];
		$dns['nowhere.example:MX'] = [];

		$result = EmailDeliverability::check( 'user@nowhere.example' );

		expect( $result->status() )->toBe( EmailCheckResult::STATUS_OK );
	}
);

it(
	'serializes a verdict for the endpoint',
	function () use ( &$dns ) {
		$dns['nowhere.example:MX']   = [];
		$dns['nowhere.example:A']    = [];
		$dns['nowhere.example:AAAA'] = [];

		$payload = EmailDeliverability::check( 'user@nowhere.example' )->toArray();

		expect( $payload['status'] )->toBe( EmailCheckResult::STATUS_NO_MX );
		expect( $payload['domain'] )->toBe( 'nowhere.example' );
		expect( $payload['blocking'] )->toBeTrue();
		expect( $payload['warning'] )->toBeFalse();
		expect( $payload['message'] )->not->toBe( '' );
	}
);

it(
	'reports what the server can do',
	function () {
		$capabilities = EmailDeliverability::capabilities();

		expect( $capabilities )->toHaveKeys( [ 'mode', 'dns', 'simple_dns', 'sockets', 'reject_unreachable' ] );
		expect( $capabilities['mode'] )->toBe( EmailDeliverability::MODE_MX );
	}
);
