<?php
/**
 * WPForms field mapping unit tests (doc §47).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use RelevantForms\Import\WpFormsFieldMapper;

it(
	'maps common WPForms field types to the Relevant schema',
	function () {
		$text = WpFormsFieldMapper::map(
			[
				'id'          => '1',
				'type'        => 'text',
				'label'       => 'Name',
				'required'    => '1',
				'placeholder' => 'Your name',
			]
		);

		expect( $text['definition']['type'] )->toBe( 'text' );
		expect( $text['definition']['label'] )->toBe( 'Name' );
		expect( $text['definition']['required'] )->toBeTrue();
		expect( $text['definition']['placeholder'] )->toBe( 'Your name' );

		expect(
			WpFormsFieldMapper::map(
				[
					'id'   => '2',
					'type' => 'phone',
				]
			)['definition']['type']
		)->toBe( 'tel' );
		expect(
			WpFormsFieldMapper::map(
				[
					'id'   => '3',
					'type' => 'textarea',
				]
			)['definition']['type']
		)->toBe( 'textarea' );
		expect(
			WpFormsFieldMapper::map(
				[
					'id'   => '4',
					'type' => 'email',
				]
			)['definition']['type']
		)->toBe( 'email' );
		expect(
			WpFormsFieldMapper::map(
				[
					'id'   => '5',
					'type' => 'hidden',
				]
			)['definition']['type']
		)->toBe( 'hidden' );
		expect(
			WpFormsFieldMapper::map(
				[
					'id'   => '6',
					'type' => 'date',
				]
			)['definition']['type']
		)->toBe( 'date' );
	}
);

it(
	'normalizes choices for select, radio and checkbox fields',
	function () {
		$mapped = WpFormsFieldMapper::map(
			[
				'id'      => '7',
				'type'    => 'select',
				'label'   => 'Budget',
				'choices' => [
					[
						'label' => 'Low',
						'value' => 'low',
					],
					[
						'label' => 'High',
						'value' => '',
					],
					[
						'label' => '',
						'value' => 'ignored',
					],
				],
			]
		);

		expect( $mapped['definition']['choices'] )->toBe(
			[
				[
					'label' => 'Low',
					'value' => 'low',
				],
				[
					'label' => 'High',
					'value' => 'High',
				],
			]
		);
	}
);

it(
	'converts upload fields to secure storage with a scan-first policy',
	function () {
		$mapped = WpFormsFieldMapper::map(
			[
				'id'              => '8',
				'type'            => 'file-upload',
				'label'           => 'Attachments',
				'extensions'      => 'pdf,jpg .docx',
				'max_size'        => 5242880,
				'max_file_number' => 3,
			]
		);

		expect( $mapped['definition']['type'] )->toBe( 'file' );
		expect( $mapped['definition']['upload_policy']['allowed_ext'] )->toBe( [ 'pdf', 'jpg', 'docx' ] );
		expect( $mapped['definition']['upload_policy']['max_size'] )->toBe( 5242880 );
		expect( $mapped['definition']['upload_policy']['max_files'] )->toBe( 3 );
		expect( $mapped['definition']['upload_policy']['scan'] )->toBeTrue();
		expect( implode( ' ', $mapped['notes'] ) )->toContain( 'secure storage' );
	}
);

it(
	'flattens compound name fields with a migration note',
	function () {
		$mapped = WpFormsFieldMapper::map(
			[
				'id'     => '9',
				'type'   => 'name',
				'label'  => 'Full name',
				'format' => 'first-last',
			]
		);

		expect( $mapped['definition']['type'] )->toBe( 'text' );
		expect( implode( ' ', $mapped['notes'] ) )->toContain( 'flattened' );
	}
);

it(
	'skips unsupported and unknown field types',
	function () {
		expect(
			WpFormsFieldMapper::map(
				[
					'id'   => '10',
					'type' => 'payment-single',
				]
			)
		)->toBeNull();
		expect(
			WpFormsFieldMapper::map(
				[
					'id'   => '11',
					'type' => 'signature',
				]
			)
		)->toBeNull();
		expect(
			WpFormsFieldMapper::map(
				[
					'id'   => '12',
					'type' => 'address',
				]
			)
		)->toBeNull();
		expect(
			WpFormsFieldMapper::map(
				[
					'id'   => '13',
					'type' => 'likert_scale',
				]
			)
		)->toBeNull();
		expect(
			WpFormsFieldMapper::map(
				[
					'id'   => '14',
					'type' => 'totally-unknown',
				]
			)
		)->toBeNull();
		expect(
			WpFormsFieldMapper::map(
				[
					'id'   => '15',
					'type' => '',
				]
			)
		)->toBeNull();
	}
);

it(
	'leaves conditional logic to the form-level conversion',
	function () {
		$mapped = WpFormsFieldMapper::map(
			[
				'id'           => '16',
				'type'         => 'text',
				'conditionals' => [
					'1' => [ 'action' => 'show' ],
				],
			]
		);

		// Conditional logic is converted by WpFormsForm (it needs the full
		// imported field set), so the per-field mapper adds no note.
		expect( $mapped['notes'] )->toBe( [] );
	}
);

it(
	'normalizes a single choice to the value the rendered field submits',
	function () {
		expect(
			WpFormsFieldMapper::choiceValue(
				[
					'label' => 'Pro',
					'value' => 'pro',
				]
			)
		)->toBe( 'pro' );
		expect( WpFormsFieldMapper::choiceValue( [ 'label' => 'Free' ] ) )->toBe( 'Free' );
		expect(
			WpFormsFieldMapper::choiceValue(
				[
					'label' => 'Free',
					'value' => '',
				]
			)
		)->toBe( 'Free' );

		// An unusable choice (no label) is dropped from the import.
		expect( WpFormsFieldMapper::choiceValue( [ 'value' => 'orphan' ] ) )->toBe( '' );
	}
);
