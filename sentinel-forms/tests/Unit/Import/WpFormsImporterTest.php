<?php
/**
 * WPForms importer schema building unit tests (doc §47).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use Brain\Monkey;
use SentinelForms\Import\WpFormsForm;
use SentinelForms\Import\WpFormsImporter;

beforeEach(
	function () {
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Monkey\Functions\when( 'current_time' )->justReturn( '2026-09-15 12:00:00' );
	}
);

/**
 * Build a parsed form for schema tests.
 *
 * @throws RuntimeException When the fixture payload is invalid.
 */
function sf_importer_form(): WpFormsForm {
	$form = WpFormsForm::fromArray(
		9,
		'Request a Quote',
		[
			'fields'   => [
				[
					'id'       => '0',
					'type'     => 'text',
					'label'    => 'Name',
					'required' => '1',
				],
				[
					'id'         => '1',
					'type'       => 'file-upload',
					'label'      => 'Plans',
					'extensions' => 'pdf',
				],
				[
					'id'    => '2',
					'type'  => 'signature',
					'label' => 'Signature',
				],
			],
			'settings' => [
				'notifications' => [ '1' => [ 'email' => 'sales@example.com' ] ],
				'confirmations' => [
					'1' => [
						'type'    => 'message',
						'message' => 'Thanks!',
					],
				],
			],
		]
	);

	if ( null === $form ) {
		throw new RuntimeException( 'Fixture form is invalid.' );
	}

	return $form;
}

it(
	'builds a canonical schema with mapped fields and provenance metadata',
	function () {
		$schema = ( new WpFormsImporter() )->buildSchema( sf_importer_form() );

		$fields = $schema->fields();

		expect( $fields )->toHaveCount( 2 );
		expect( $fields[0]['id'] )->toBe( '0' );
		expect( $fields[0]['type'] )->toBe( 'text' );
		expect( $fields[0]['required'] )->toBeTrue();
		expect( $fields[1]['type'] )->toBe( 'file' );
		expect( $fields[1]['upload_policy']['scan'] )->toBeTrue();

		expect( $schema->notificationEmails() )->toBe( [ 'sales@example.com' ] );
		expect( $schema->confirmationMessage() )->toBe( 'Thanks!' );
		expect( $schema->metadata() )->toMatchArray(
			[
				'imported_from'   => 'wpforms',
				'wpforms_form_id' => 9,
				'imported_at'     => '2026-09-15 12:00:00',
			]
		);
	}
);

it(
	'attaches migration notes for file and skipped fields',
	function () {
		$importer   = new WpFormsImporter();
		$form       = sf_importer_form();
		$reflection = new ReflectionMethod( $importer, 'notes' );

		$notes = implode( ' ', (array) $reflection->invoke( $importer, $form ) );

		expect( $notes )->toContain( 'secure storage' );
		expect( $notes )->toContain( 'draft' );
	}
);
