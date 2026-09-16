<?php
/**
 * Field type validation and sanitization unit tests.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use Brain\Monkey;
use SentinelForms\Forms\Fields\FieldTypeRegistry;

beforeEach(
	function () {
		FieldTypeRegistry::all();
	}
);

it(
	'accepts and trims plain text',
	function () {
		$type = FieldTypeRegistry::get( 'text' );

		expect( $type->validate( [], '  hello  ' ) )->toBeNull();
		expect( $type->sanitize( [], '  hello  ' ) )->toBe( 'hello' );
	}
);

it(
	'rejects overlong text',
	function () {
		$type = FieldTypeRegistry::get( 'text' );

		expect( $type->validate( [], str_repeat( 'a', 501 ) ) )->not->toBeNull();
	}
);

it(
	'validates email addresses',
	function () {
		$type = FieldTypeRegistry::get( 'email' );

		expect( $type->validate( [], 'john@example.com' ) )->toBeNull();
		expect( $type->validate( [], 'not-an-email' ) )->not->toBeNull();
		expect( $type->validate( [], '' ) )->toBeNull();
	}
);

it(
	'validates phone numbers conservatively',
	function () {
		$type = FieldTypeRegistry::get( 'tel' );

		expect( $type->validate( [], '+39 333 123456' ) )->toBeNull();
		expect( $type->validate( [], '"><script>' ) )->not->toBeNull();
	}
);

it(
	'validates urls with http(s) schemes only',
	function () {
		$type = FieldTypeRegistry::get( 'url' );

		expect( $type->validate( [], 'https://example.com' ) )->toBeNull();
		expect( $type->validate( [], 'javascript:alert(1)' ) )->not->toBeNull();
		expect( $type->validate( [], 'ftp://example.com' ) )->not->toBeNull();
	}
);

it(
	'validates numbers',
	function () {
		$type = FieldTypeRegistry::get( 'number' );

		expect( $type->validate( [], '42' ) )->toBeNull();
		expect( $type->validate( [], '4.2' ) )->toBeNull();
		expect( $type->validate( [], 'abc' ) )->not->toBeNull();
	}
);

it(
	'validates dates strictly',
	function () {
		$type = FieldTypeRegistry::get( 'date' );

		expect( $type->validate( [], '2026-09-14' ) )->toBeNull();
		expect( $type->validate( [], '2026-02-30' ) )->not->toBeNull();
		expect( $type->validate( [], '14/09/2026' ) )->not->toBeNull();
	}
);

it(
	'enforces select allowed values',
	function () {
		$type       = FieldTypeRegistry::get( 'select' );
		$definition = [ 'choices' => [ 'Red|red', 'Blue|blue' ] ];

		expect( $type->validate( $definition, 'red' ) )->toBeNull();
		expect( $type->validate( $definition, 'green' ) )->not->toBeNull();
		expect( $type->sanitize( $definition, 'blue' ) )->toBe( 'blue' );
		expect( $type->sanitize( $definition, 'green' ) )->toBe( '' );
	}
);

it(
	'sanitizes checkbox groups to allowed values only',
	function () {
		$type       = FieldTypeRegistry::get( 'checkbox' );
		$definition = [ 'choices' => [ 'A|a', 'B|b' ] ];

		expect( $type->sanitize( $definition, [ 'a', 'z' ] ) )->toBe( [ 'a' ] );
		expect( $type->validate( $definition, [ 'a', 'z' ] ) )->not->toBeNull();
	}
);

it(
	'normalizes consent values',
	function () {
		$type = FieldTypeRegistry::get( 'consent' );

		expect( $type->sanitize( [], '1' ) )->toBe( '1' );
		expect( $type->sanitize( [], '' ) )->toBe( '' );
		expect( $type->validate( [], 'true' ) )->not->toBeNull();
	}
);

it(
	'registers all thirteen built-in types',
	function () {
		expect( FieldTypeRegistry::all() )->toHaveCount( 13 );
	}
);

it(
	'renders accessible errors and live upload status',
	function () {
		Monkey\Functions\when( 'esc_attr' )->returnArg();
		Monkey\Functions\when( 'esc_html' )->returnArg();

		$type       = FieldTypeRegistry::get( 'email' );
		$definition = [
			'id'    => 'email',
			'type'  => 'email',
			'label' => 'Email',
		];

		$markup = $type->render( $definition, '', 'Enter a valid address.', '7' );

		expect( $markup )->toContain( 'id="sentinel-field-7-email-error"' );
		expect( $markup )->toContain( 'role="alert"' );

		$fileMarkup = FieldTypeRegistry::get( 'file' )->render(
			[
				'id'    => 'cv',
				'type'  => 'file',
				'label' => 'CV',
			],
			'',
			null,
			'7'
		);

		expect( $fileMarkup )->toContain( 'role="status"' );
		expect( $fileMarkup )->toContain( 'aria-live="polite"' );
	}
);
