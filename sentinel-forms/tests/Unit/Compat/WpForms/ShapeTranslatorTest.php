<?php
/**
 * WPForms shape translation unit tests (docs/wpforms-compatibility.md).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use SentinelForms\Compat\WpForms\ShapeTranslator;
use SentinelForms\Forms\Form;
use SentinelForms\Forms\FormSchema;

/**
 * Build a form entity for translation tests.
 */
function sf_compat_form(): Form {
	$schema = FormSchema::fromArray(
		[
			'name'   => 'Contact',
			'fields' => [
				[
					'id'       => '0',
					'type'     => 'text',
					'label'    => 'Name',
					'required' => true,
				],
				[
					'id'    => '1',
					'type'  => 'email',
					'label' => 'Email',
				],
				[
					'id'    => '2',
					'type'  => 'tel',
					'label' => 'Phone',
				],
				[
					'id'    => '3',
					'type'  => 'file',
					'label' => 'Files',
				],
				[
					'id'    => 'f_check',
					'type'  => 'checkbox',
					'label' => 'Topics',
				],
			],
		]
	);

	return new Form( 12, 'Contact', 'published', $schema, '2026-01-01 00:00:00', '2026-01-01 00:00:00' );
}

it(
	'builds WPForms-shaped fields with ids, names, values and types',
	function () {
		$fields = ShapeTranslator::fields(
			sf_compat_form(),
			[
				'0'       => 'Ada',
				'1'       => 'ada@example.com',
				'2'       => '07700 900123',
				'3'       => '["3"]',
				'f_check' => [ 'SEO', 'News' ],
			]
		);

		expect( $fields['0'] )->toBe(
			[
				'id'    => 0,
				'name'  => 'Name',
				'value' => 'Ada',
				'type'  => 'text',
			]
		);
		expect( $fields['1']['type'] )->toBe( 'email' );
		expect( $fields['2']['type'] )->toBe( 'phone' );
		expect( $fields['3']['type'] )->toBe( 'file-upload' );
		expect( $fields['f_check']['id'] )->toBe( 'f_check' );
		expect( $fields['f_check']['value'] )->toBe( "SEO\nNews" );
	}
);

it(
	'builds WPForms-shaped form data',
	function () {
		$formData = ShapeTranslator::formData( sf_compat_form() );

		expect( $formData['id'] )->toBe( 12 );
		expect( $formData['name'] )->toBe( 'Contact' );
		expect( $formData['fields']['0']['required'] )->toBe( '1' );
		expect( $formData['fields']['1']['required'] )->toBe( '' );
		expect( $formData['settings']['form_title'] )->toBe( 'Contact' );
	}
);
