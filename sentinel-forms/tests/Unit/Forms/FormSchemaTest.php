<?php
/**
 * FormSchema unit tests.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use SentinelForms\Forms\FormSchema;

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
