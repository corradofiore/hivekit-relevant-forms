<?php
/**
 * FormSchema unit tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use RelevantForms\Forms\FormSchema;

it(
	'creates an empty schema with version 1',
	function () {
		$schema = FormSchema::empty( 'Contact' );

		expect( $schema->toArray() )->toMatchArray(
			[
				'schema_version' => 1,
				'name'           => 'Contact',
				'fields'         => [],
				'notifications'  => [],
			]
		);
	}
);

it(
	'normalizes raw arrays on import',
	function () {
		$schema = FormSchema::fromArray(
			[
				'name'   => 'Quote',
				'fields' => [
					[
						'id'    => 'f_1',
						'type'  => 'text',
						'label' => 'Name',
					],
				],
				'bogus'  => 'ignored',
			]
		);

		expect( $schema->name() )->toBe( 'Quote' );
		expect( $schema->fields() )->toHaveCount( 1 );
		expect( $schema->toArray()['schema_version'] )->toBe( 1 );
	}
);

it(
	'carries a normalized payment currency with a usd fallback',
	function () {
		$schema = FormSchema::empty( 'Payments' );

		expect( $schema->currency() )->toBe( 'usd' );

		$schema = $schema->withCurrency( 'EUR' );

		expect( $schema->currency() )->toBe( 'eur' );
		expect( $schema->toArray()['currency'] )->toBe( 'eur' );

		// The currency survives the storage round-trip normalization.
		expect( FormSchema::fromArray( $schema->toArray() )->currency() )->toBe( 'eur' );

		// Unsupported values fall back at write and read time.
		expect( $schema->withCurrency( 'xyz' )->currency() )->toBe( 'usd' );
		expect(
			FormSchema::fromArray(
				[
					'name'     => 'Legacy',
					'fields'   => [],
					'currency' => 'xxc',
				]
			)->currency()
		)->toBe( 'usd' );
	}
);

it(
	'normalizes field visibility configs on read',
	function () {
		$schema = FormSchema::fromArray(
			[
				'name'   => 'Conditional',
				'fields' => [
					[
						'id'         => 'f_extra',
						'type'       => 'text',
						'label'      => 'Extra',
						'visibility' => [
							'default' => 'hidden',
							'groups'  => [
								[
									[
										'field'    => 'f_plan',
										'operator' => '==',
										'value'    => 'pro',
									],
								],
							],
						],
					],
					[
						'id'         => 'f_plain',
						'type'       => 'text',
						'label'      => 'Plain',
						'visibility' => [
							'default' => 'hidden',
							'groups'  => [ [] ],
						],
					],
				],
			]
		);

		$fields = $schema->fields();

		// Legacy operator spellings are canonicalized on read.
		expect( $fields[0]['visibility']['default'] )->toBe( 'hidden' );
		expect( $fields[0]['visibility']['groups'][0][0]['operator'] )->toBe( 'is' );

		// Configurations without a complete group are dropped entirely.
		expect( $fields[1] )->not->toHaveKey( 'visibility' );
	}
);

it(
	'round-trips through JSON',
	function () {
		$schema = FormSchema::empty( 'Round trip' )
		->withNotifications( [ 'a@example.com' ] );

		$decoded = json_decode( (string) json_encode( $schema->jsonSerialize() ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure unit test, no WordPress bootstrap.

		expect( FormSchema::fromArray( $decoded )->notificationEmails() )->toBe( [ 'a@example.com' ] );
	}
);

it(
	'detects duplicate field ids',
	function () {
		$schema = FormSchema::empty()
		->withFields(
			[
				[
					'id'    => 'f_1',
					'type'  => 'text',
					'label' => 'A',
				],
				[
					'id'    => 'f_1',
					'type'  => 'text',
					'label' => 'B',
				],
			]
		);

		expect( $schema->validate() )->toContain( 'Duplicate field ids.' );
	}
);

it(
	'detects unknown field types and missing labels',
	function () {
		$schema = FormSchema::empty()
		->withFields(
			[
				[
					'id'    => 'f_1',
					'type'  => 'quantum',
					'label' => '?',
				],
				[
					'id'    => 'f_2',
					'type'  => 'text',
					'label' => '',
				],
			]
		);

		$errors = $schema->validate();

		expect( $errors )->toContain( 'Unknown field type "quantum".' );
		expect( $errors )->toContain( 'Field "f_2" is missing a label.' );
	}
);

it(
	'allows hidden fields without labels',
	function () {
		$schema = FormSchema::empty()
		->withFields(
			[
				[
					'id'            => 'f_1',
					'type'          => 'hidden',
					'label'         => '',
					'default_value' => 'x',
				],
			]
		);

		expect( $schema->validate() )->toBe( [] );
	}
);

it(
	'stores the redirect anchor and round-trips it through arrays',
	function () {
		$schema = FormSchema::empty()
		->withConfirmation( 'Thanks!', 'https://example.com/legacy', 42 );

		expect( $schema->redirectPageId() )->toBe( 42 );
		expect( $schema->redirectUrl() )->toBe( 'https://example.com/legacy' );

		$restored = FormSchema::fromArray( $schema->jsonSerialize() );

		expect( $restored->redirectPageId() )->toBe( 42 );
		expect( $restored->confirmationMessage() )->toBe( 'Thanks!' );
	}
);

it(
	'defaults the redirect anchor to none',
	function () {
		expect( FormSchema::empty()->redirectPageId() )->toBe( 0 );

		$legacy = FormSchema::fromArray( [ 'confirmation' => [ 'redirect' => 'https://example.com' ] ] );

		expect( $legacy->redirectPageId() )->toBe( 0 );
	}
);

it(
	'stores the confirmation mode and infers it for legacy schemas',
	function () {
		$schema = FormSchema::empty()->withConfirmation( 'Thanks!', '', 0, 'message' );

		expect( $schema->confirmationMode() )->toBe( 'message' );

		$legacyUrl = FormSchema::fromArray( [ 'confirmation' => [ 'redirect' => 'https://example.com' ] ] );

		expect( $legacyUrl->confirmationMode() )->toBe( 'url' );

		$legacyPage = FormSchema::fromArray( [ 'confirmation' => [ 'redirect_page' => 7 ] ] );

		expect( $legacyPage->confirmationMode() )->toBe( 'page' );

		$plain = FormSchema::fromArray( [] );

		expect( $plain->confirmationMode() )->toBe( 'message' );
	}
);

it(
	'ignores invalid confirmation modes',
	function () {
		$schema = FormSchema::empty()->withConfirmation( '', 'https://example.com', 0, 'bogus' );

		expect( $schema->confirmationMode() )->toBe( 'url' );

		$restored = FormSchema::fromArray(
			[
				'confirmation' => [
					'mode'          => 'nope',
					'redirect_page' => 3,
				],
			]
		);

		expect( $restored->confirmationMode() )->toBe( 'page' );
	}
);

it(
	'rejects invalid notification emails',
	function () {
		$schema = FormSchema::empty()->withNotifications( [ 'not-an-email' ] );

		expect( $schema->validate() )->toContain( 'Invalid notification email "not-an-email".' );
	}
);

it(
	'de-duplicates notification emails',
	function () {
		$schema = FormSchema::empty()->withNotifications( [ 'a@example.com', 'a@example.com', ' b@example.com ' ] );

		expect( $schema->notificationEmails() )->toBe( [ 'a@example.com', 'b@example.com' ] );
	}
);
