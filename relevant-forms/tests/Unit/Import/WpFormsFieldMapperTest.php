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
		// Unknown payment types (addon fields) stay unsupported; the standard
		// payment fields map with amounts preserved (phase 5).
		expect(
			WpFormsFieldMapper::map(
				[
					'id'   => '10',
					'type' => 'payment-coupon',
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
	'maps payment fields with amounts preserved',
	function () {
		$single = WpFormsFieldMapper::map(
			[
				'id'     => '20',
				'type'   => 'payment-single',
				'label'  => 'Deposit',
				'format' => 'single',
				'price'  => '25.00',
			]
		);

		expect( $single['definition']['type'] )->toBe( 'payment-single' );
		expect( $single['definition']['amount'] )->toBe( '25.00' );
		expect( $single['definition']['user_amount'] ?? false )->toBeFalse();

		$donation = WpFormsFieldMapper::map(
			[
				'id'        => '21',
				'type'      => 'payment-single',
				'label'     => 'Donation',
				'format'    => 'user',
				'min_price' => '5',
			]
		);

		expect( $donation['definition']['user_amount'] )->toBeTrue();
		expect( $donation['definition']['min_amount'] )->toBe( '5' );
		expect( implode( ' ', $donation['notes'] ) )->toContain( 'donation' );

		$select = WpFormsFieldMapper::map(
			[
				'id'      => '22',
				'type'    => 'payment-select',
				'label'   => 'Merch',
				'choices' => [
					'1' => [
						'label' => 'T-shirt',
						'value' => '25.00',
					],
					'2' => [
						'label' => 'Cap',
						'value' => '10',
					],
					'3' => [ 'label' => 'Samples' ],
				],
			]
		);

		expect( $select['definition']['type'] )->toBe( 'payment-select' );
		expect( $select['definition']['choices'] )->toBe( [ 'T-shirt|25.00', 'Cap|10' ] );
		expect( implode( ' ', $select['notes'] ) )->toContain( 'skipped' );

		$total = WpFormsFieldMapper::map(
			[
				'id'    => '23',
				'type'  => 'payment-total',
				'label' => 'Total',
			]
		);

		expect( $total['definition']['type'] )->toBe( 'payment-total' );
	}
);

it(
	'resolves payment choice keys to imported option positions',
	function () {
		$field = [
			'choices' => [
				'1' => [
					'label' => 'T-shirt',
					'value' => '25.00',
				],
				'2' => [
					'label' => 'Cap',
					'value' => '10',
				],
				'3' => [
					'label' => 'Broken',
					'value' => 'nope',
				],
			],
		];

		expect( WpFormsFieldMapper::paymentPositionByKey( '1', $field ) )->toBe( '0' );
		expect( WpFormsFieldMapper::paymentPositionByKey( '2', $field ) )->toBe( '1' );
		// The skipped option and unknown keys have no position.
		expect( WpFormsFieldMapper::paymentPositionByKey( '3', $field ) )->toBeNull();
		expect( WpFormsFieldMapper::paymentPositionByKey( '9', $field ) )->toBeNull();
		expect( WpFormsFieldMapper::paymentPositionByKey( '1', null ) )->toBeNull();
	}
);

it(
	'maps date-time fields by their format mode',
	function () {
		$date = WpFormsFieldMapper::map(
			[
				'id'       => '30',
				'type'     => 'date-time',
				'label'    => 'DOB',
				'format'   => 'date',
				'required' => '1',
			]
		);

		expect( $date['definition']['type'] )->toBe( 'date' );
		expect( $date['definition']['required'] )->toBeTrue();

		$datetime = WpFormsFieldMapper::map(
			[
				'id'     => '31',
				'type'   => 'date-time',
				'label'  => 'Appointment',
				'format' => 'date-time',
			]
		);

		expect( $datetime['definition']['type'] )->toBe( 'text' );
		expect( implode( ' ', $datetime['notes'] ) )->toContain( 'time' );
	}
);

it(
	'explains skip reasons for known types',
	function () {
		expect( WpFormsFieldMapper::skipReason( 'stripe-credit-card' ) )->toContain( 'Stripe' );
		expect( WpFormsFieldMapper::skipReason( 'entry-preview' ) )->toContain( 'Layout' );
		expect( WpFormsFieldMapper::skipReason( 'signature' ) )->toContain( 'Unsupported' );
	}
);

it(
	'maps page breaks with the page title as label',
	function () {
		$titled = WpFormsFieldMapper::map(
			[
				'id'    => '2',
				'type'  => 'pagebreak',
				'title' => 'Passenger details',
			]
		);

		expect( $titled['definition']['type'] )->toBe( 'pagebreak' );
		expect( $titled['definition']['label'] )->toBe( 'Passenger details' );

		// A page break without a title stays label-less on purpose.
		$bare = WpFormsFieldMapper::map(
			[
				'id'   => '3',
				'type' => 'pagebreak',
			]
		);

		expect( $bare['definition']['type'] )->toBe( 'pagebreak' );
		expect( $bare['definition']['label'] )->toBe( '' );
	}
);

it(
	'maps dividers to label-less sections',
	function () {
		$mapped = WpFormsFieldMapper::map(
			[
				'id'   => '4',
				'type' => 'divider',
			]
		);

		expect( $mapped['definition']['type'] )->toBe( 'section' );
		expect( $mapped['definition']['label'] )->toBe( '' );
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
