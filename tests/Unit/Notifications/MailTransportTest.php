<?php
/**
 * Mail transport detection unit tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Notifications\MailTransport;

it(
	'reports the mail() fallback when nothing owns the transport',
	function () {
		Monkey\Functions\when( 'has_action' )->justReturn( false );
		Monkey\Functions\when( 'has_filter' )->justReturn( false );

		expect( MailTransport::isConfigured() )->toBeFalse();
	}
);

it(
	'accepts a mailer that configures PHPMailer',
	function () {
		Monkey\Functions\when( 'has_action' )->justReturn( 10 );
		Monkey\Functions\when( 'has_filter' )->justReturn( false );

		expect( MailTransport::isConfigured() )->toBeTrue();
	}
);

it(
	'accepts a relay that answers wp_mail() itself',
	function () {
		Monkey\Functions\when( 'has_action' )->justReturn( false );
		Monkey\Functions\when( 'has_filter' )->justReturn( 10 );

		expect( MailTransport::isConfigured() )->toBeTrue();
	}
);
