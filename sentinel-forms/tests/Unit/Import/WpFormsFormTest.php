<?php
/**
 * WPForms form parsing and analysis unit tests (doc §46).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use Brain\Monkey;
use SentinelForms\Import\WpFormsForm;

beforeEach(
	function () {
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}
);

/**
 * Build a parsed form from a decoded WPForms payload.
 *
 * @param array<string, mixed> $data Payload overrides.
 * @throws RuntimeException When the fixture payload is invalid.
 */
function sf_wpforms_form( array $data = [] ): WpFormsForm {
	$form = WpFormsForm::fromArray(
		42,
		'Contact Us',
		array_merge(
			[
				'fields'   => [
					'0' => [
						'id'    => '0',
						'type'  => 'text',
						'label' => 'Name',
					],
					'1' => [
						'id'       => '1',
						'type'     => 'email',
						'label'    => 'Email',
						'required' => '1',
					],
				],
				'settings' => [],
			],
			$data
		)
	);

	if ( null === $form ) {
		throw new RuntimeException( 'Fixture form is invalid.' );
	}

	return $form;
}

it(
	'parses json payloads and normalizes field ids',
	function () {
		$json = (string) wp_json_encode(
			[
				'fields'   => [
					[
						'type'  => 'text',
						'label' => 'First',
					],
				],
				'settings' => [ 'form_title' => 'From settings' ],
			]
		);

		$form = WpFormsForm::fromJson( 7, 'Post title', $json );

		expect( $form )->not->toBeNull();
		expect( $form->fields[0]['id'] )->toBe( '0' );
		expect( $form->title )->toBe( 'Post title' );
	}
);

it(
	'rejects payloads without a fields array',
	function () {
		expect( WpFormsForm::fromArray( 1, 'X', [] ) )->toBeNull();
		expect( WpFormsForm::fromJson( 1, 'X', 'not-json' ) )->toBeNull();
	}
);

it(
	'extracts notification emails and explains smart-tag fallbacks',
	function () {
		$form = sf_wpforms_form(
			[
				'settings' => [
					'notifications' => [
						'1' => [ 'email' => 'sales@example.com, ops@example.com' ],
						'2' => [ 'email' => '{admin_email}' ],
					],
				],
			]
		);

		$notifications = $form->notifications();

		expect( $notifications['emails'] )->toBe( [ 'sales@example.com', 'ops@example.com' ] );
		expect( implode( ' ', $notifications['notes'] ) )->toContain( 'smart tags' );
	}
);

it(
	'extracts message and redirect confirmations',
	function () {
		$message = sf_wpforms_form(
			[
				'settings' => [
					'confirmations' => [
						'1' => [
							'type'    => 'message',
							'message' => 'Thanks!',
						],
					],
				],
			]
		);

		expect( $message->confirmation() )->toBe(
			[
				'message'  => 'Thanks!',
				'redirect' => '',
			]
		);

		$redirect = sf_wpforms_form(
			[
				'settings' => [
					'confirmations' => [
						'1' => [
							'type'     => 'redirect',
							'redirect' => 'https://example.com/thanks',
						],
					],
				],
			]
		);

		expect( $redirect->confirmation() )->toBe(
			[
				'message'  => '',
				'redirect' => 'https://example.com/thanks',
			]
		);
	}
);

it(
	'classifies fully compatible forms',
	function () {
		$analysis = sf_wpforms_form()->analysis();

		expect( $analysis['status'] )->toBe( WpFormsForm::STATUS_COMPATIBLE );
		expect( $analysis['issues'] )->toBe( [] );
	}
);

it(
	'flags forms requiring review and forms with integrations',
	function () {
		$review = sf_wpforms_form(
			[
				'fields' => [
					[
						'id'    => '0',
						'type'  => 'text',
						'label' => 'Name',
					],
					[
						'id'    => '1',
						'type'  => 'signature',
						'label' => 'Signature',
					],
				],
			]
		);

		expect( $review->analysis()['status'] )->toBe( WpFormsForm::STATUS_REVIEW );
		expect( $review->skippedFields() )->toHaveCount( 1 );
		expect( implode( ' ', $review->analysis()['issues'] ) )->toContain( 'Signature' );

		$unsupported = sf_wpforms_form(
			[
				'fields' => [
					[
						'id'    => '0',
						'type'  => 'payment-single',
						'label' => 'Pay',
					],
				],
			]
		);

		expect( $unsupported->analysis()['status'] )->toBe( WpFormsForm::STATUS_UNSUPPORTED );
	}
);

it(
	'detects uploads and conditional logic in the analysis',
	function () {
		$form = sf_wpforms_form(
			[
				'fields' => [
					[
						'id'    => '0',
						'type'  => 'file-upload',
						'label' => 'Files',
					],
					[
						'id'               => '1',
						'type'             => 'text',
						'label'            => 'Extra',
						'conditional_type' => 'show',
						'conditionals'     => [
							'1' => [
								'1' => [
									'field'    => '0',
									'operator' => '!e',
									'value'    => '',
								],
							],
						],
					],
				],
			]
		);

		$analysis = $form->analysis();

		expect( $analysis['uploads'] )->toBeTrue();
		expect( $analysis['conditionals'] )->toBeTrue();

		// Conditional logic now converts, so it no longer forces a review.
		expect( $analysis['status'] )->toBe( WpFormsForm::STATUS_COMPATIBLE );
	}
);

it(
	'flags conditional rules that cannot be imported',
	function () {
		$form = sf_wpforms_form(
			[
				'fields' => [
					[
						'id'    => '0',
						'type'  => 'text',
						'label' => 'Name',
					],
					[
						'id'               => '1',
						'type'             => 'text',
						'label'            => 'Extra',
						'conditional_type' => 'show',
						'conditionals'     => [
							'1' => [
								'1' => [
									'field'    => '99',
									'operator' => '==',
									'value'    => 'x',
								],
							],
						],
					],
				],
			]
		);

		$analysis = $form->analysis();

		expect( $analysis['conditionals'] )->toBeTrue();
		expect( $analysis['status'] )->toBe( WpFormsForm::STATUS_REVIEW );
		expect( implode( ' ', $analysis['issues'] ) )->toContain( 'could not be imported' );
	}
);

it(
	'converts conditional logic into visibility rules on the mapped fields',
	function () {
		$form = sf_wpforms_form(
			[
				'fields' => [
					[
						'id'      => '1',
						'type'    => 'select',
						'label'   => 'Plan',
						'choices' => [
							'1' => [
								'label' => 'Pro',
								'value' => 'pro',
							],
							'2' => [ 'label' => 'Free' ],
						],
					],
					[
						'id'               => '2',
						'type'             => 'text',
						'label'            => 'Company',
						'conditional_type' => 'show',
						'conditionals'     => [
							'1' => [
								'1' => [
									'field'    => '1',
									'operator' => '==',
									'value'    => '1',
								],
							],
						],
					],
				],
			]
		);

		$mapped = $form->mappedFields();

		expect( $mapped[0]['definition'] )->not->toHaveKey( 'visibility' );
		expect( $mapped[1]['definition']['visibility'] )->toBe(
			[
				'default' => 'hidden',
				'groups'  => [
					[
						[
							'field'    => '1',
							'operator' => 'is',
							'value'    => 'pro',
						],
					],
				],
			]
		);
		expect( $mapped[1]['notes'] )->toBe( [] );
	}
);
