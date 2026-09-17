<?php
/**
 * WPForms compatibility layer unit tests (Phase 7.5).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Compat\WpForms\CompatLayer;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormSchema;

beforeEach(
	function () {
		Monkey\Functions\when( 'do_action' )->justReturn( null );
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );
		Monkey\Functions\when( 'wp_unslash' )->returnArg();
	}
);

/**
 * Build a form entity for the gate tests.
 */
function rf_gate_form(): Form {
	$schema = FormSchema::fromArray(
		[
			'name'   => 'Contact',
			'fields' => [
				[
					'id'    => 'f_msg',
					'type'  => 'textarea',
					'label' => 'Message',
				],
			],
		]
	);

	return new Form( 4, 'Contact', 'published', $schema, '2026-01-01 00:00:00', '2026-01-01 00:00:00' );
}

it(
	'is active by default when WPForms is absent',
	function () {
		expect( CompatLayer::shouldRegister() )->toBeTrue();
	}
);

it(
	'honors an explicit site override',
	function () {
		Monkey\Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, bool $fallback ): bool {
				return 'relevant_forms_wpforms_compat_enabled' === $hook ? false : $fallback;
			}
		);

		expect( CompatLayer::shouldRegister() )->toBeFalse();
	}
);

it(
	'maps emulated wpforms process errors and resets the gate',
	function () {
		$accessor                           = CompatLayer::accessor();
		$accessor->process->errors['f_msg'] = [
			'header' => 'Blocked',
			'footer' => 'Try again later.',
		];

		$errors = CompatLayer::process( [ 'other' => 'Existing' ], rf_gate_form(), [ 'f_msg' => 'hello' ] );

		expect( $errors['f_msg'] )->toBe( 'Blocked Try again later.' );
		expect( $errors['other'] )->toBe( 'Existing' );
		expect( $accessor->process->errors )->toBe( [] );
	}
);

it(
	'accepts plain string errors like WPForms consumers write',
	function () {
		$accessor                           = CompatLayer::accessor();
		$accessor->process->errors['f_msg'] = 'Nope';

		$errors = CompatLayer::process( [], rf_gate_form(), [] );

		expect( $errors )->toBe( [ 'f_msg' => 'Nope' ] );
	}
);

it(
	'passes submissions through when no consumer sets an error',
	function () {
		expect( CompatLayer::process( [], rf_gate_form(), [] ) )->toBe( [] );
	}
);
