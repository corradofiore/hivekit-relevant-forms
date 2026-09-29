<?php
/**
 * Sender identity unit tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Notifications\SenderIdentity;

beforeEach(
	function () {
		Monkey\Functions\when( 'is_email' )->alias(
			static function ( mixed $email ): bool {
				return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
			}
		);
	}
);

it(
	'builds the From header only when name and address are usable together',
	function () {
		$form = static function ( array $sender ): Form {
			$schema = FormSchema::fromArray(
				[
					'name'   => 'Contact',
					'sender' => $sender,
					'fields' => [],
				]
			);

			return new Form( 1, 'Contact', 'published', $schema, '2026-01-01 00:00:00', '2026-01-01 00:00:00' );
		};

		expect(
			SenderIdentity::fromHeader(
				$form(
					[
						'name'  => 'Acme Forms',
						'email' => 'no-reply@acme.example',
					]
				)
			)
		)
			->toBe( 'From: Acme Forms <no-reply@acme.example>' );

		// Both-or-nothing: a missing or invalid part drops the header.
		expect(
			SenderIdentity::fromHeader(
				$form(
					[
						'name'  => 'Acme Forms',
						'email' => 'not-an-email',
					]
				)
			)
		)->toBe( '' );
		expect(
			SenderIdentity::fromHeader(
				$form(
					[
						'name'  => '',
						'email' => 'no-reply@acme.example',
					]
				)
			)
		)->toBe( '' );
		expect( SenderIdentity::fromHeader( $form( [] ) ) )->toBe( '' );
	}
);
