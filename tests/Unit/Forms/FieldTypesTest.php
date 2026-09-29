<?php
/**
 * Field type validation and sanitization unit tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Forms\Fields\FieldTypeRegistry;

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
		$definition = [ 'choices' => [ 'red|Red', 'blue|Blue' ] ];

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
		$definition = [ 'choices' => [ 'a|A', 'b|B' ] ];

		expect( $type->sanitize( $definition, [ 'a', 'z' ] ) )->toBe( [ 'a' ] );
		expect( $type->validate( $definition, [ 'a', 'z' ] ) )->not->toBeNull();
	}
);

it(
	'blocks empty submissions of required radio groups in the browser',
	function () {
		Monkey\Functions\when( 'esc_attr' )->returnArg();
		Monkey\Functions\when( 'esc_html' )->returnArg();
		Monkey\Functions\when( 'checked' )->justReturn( '' );

		$type       = FieldTypeRegistry::get( 'radio' );
		$definition = [
			'id'       => 'pick',
			'type'     => 'radio',
			'required' => true,
			'choices'  => [ 'a|A', 'b|B' ],
		];

		$markup = $type->render( $definition, null, null, '7' );

		// Both radios carry the native attribute, so the browser enforces
		// the group the way it does for a required dropdown.
		expect( substr_count( $markup, ' required>' ) )->toBe( 2 );

		$optional = $type->render( array_merge( $definition, [ 'required' => false ] ), null, null, '7' );

		expect( $optional )->not->toContain( ' required>' );
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
	'registers all twenty-three built-in types',
	function () {
		expect( FieldTypeRegistry::all() )->toHaveCount( 23 );
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

		expect( $markup )->toContain( 'id="relevant-field-7-email-error"' );
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

it(
	'honours per-field maximum lengths with 0 meaning no limit',
	function () {
		$text     = FieldTypeRegistry::get( 'text' );
		$textarea = FieldTypeRegistry::get( 'textarea' );

		// Custom limit: rejected over the limit, accepted at it.
		expect( $text->validate( [ 'max_length' => 10 ], str_repeat( 'a', 11 ) ) )->toContain( '10' );
		expect( $text->validate( [ 'max_length' => 10 ], str_repeat( 'a', 10 ) ) )->toBeNull();

		// Explicit 0 disables the cap entirely.
		expect( $text->validate( [ 'max_length' => 0 ], str_repeat( 'a', 600 ) ) )->toBeNull();
		expect( $textarea->validate( [ 'max_length' => 0 ], str_repeat( 'a', 10001 ) ) )->toBeNull();

		// Definitions without the key keep the type defaults.
		expect( $text->validate( [], str_repeat( 'a', 501 ) ) )->not->toBeNull();
		expect( $textarea->validate( [], str_repeat( 'a', 10001 ) ) )->not->toBeNull();
		expect( $textarea->validate( [ 'max_length' => 20 ], str_repeat( 'a', 21 ) ) )->not->toBeNull();
	}
);

it(
	'renders maxlength attributes for limited text fields',
	function () {
		Monkey\Functions\when( 'esc_attr' )->returnArg();
		Monkey\Functions\when( 'esc_html' )->returnArg();
		Monkey\Functions\when( 'esc_textarea' )->returnArg();

		$text       = FieldTypeRegistry::get( 'text' );
		$textarea   = FieldTypeRegistry::get( 'textarea' );
		$definition = [
			'id'         => 'f_x',
			'type'       => 'text',
			'label'      => 'X',
			'max_length' => 25,
		];

		expect( $text->render( $definition, '', null, '7' ) )->toContain( 'maxlength="25"' );

		$definition['max_length'] = 0;

		expect( $text->render( $definition, '', null, '7' ) )->not->toContain( 'maxlength' );

		$definition['max_length'] = 40;
		$definition['type']       = 'textarea';

		expect( $textarea->render( $definition, '', null, '7' ) )->toContain( 'maxlength="40"' );

		// Definitions without the key fall back to the type default.
		$legacy = [
			'id'    => 'f_x',
			'type'  => 'text',
			'label' => 'X',
		];

		expect( $text->render( $legacy, '', null, '7' ) )->toContain( 'maxlength="500"' );
	}
);

it(
	'renders a character counter for limited paragraph fields',
	function () {
		Monkey\Functions\when( 'esc_attr' )->returnArg();
		Monkey\Functions\when( 'esc_html' )->returnArg();
		Monkey\Functions\when( 'esc_textarea' )->returnArg();

		$textarea   = FieldTypeRegistry::get( 'textarea' );
		$definition = [
			'id'         => 'f_msg',
			'type'       => 'textarea',
			'label'      => 'Message',
			'max_length' => 500,
		];

		// Empty field: the counter starts at zero against the limit.
		expect( $textarea->render( $definition, '', null, '7' ) )
			->toContain( '<p class="relevant-forms-char-count" data-rf-count-for="relevant-field-7-f_msg">0/500</p>' );

		// Prefilled values (repopulated after a failed submission) count too.
		expect( $textarea->render( $definition, 'Héllo!', null, '7' ) )
			->toContain( '>6/500</p>' );

		// Without a limit there is no total: hidden, count only.
		$definition['max_length'] = 0;

		expect( $textarea->render( $definition, 'Hi', null, '7' ) )
			->toContain( '<p class="relevant-forms-char-count" data-rf-count-for="relevant-field-7-f_msg" hidden>2</p>' );

		// Help text sits below the counter (control, counter, help): the
		// editor preview mirrors this order when a help text is typed.
		$definition['max_length'] = 500;
		$definition['help_text']  = 'Keep it short';

		expect( $textarea->render( $definition, 'Hi', null, '7' ) )
			->toContain( '>2/500</p><p class="relevant-forms-help" id="relevant-field-7-f_msg-help">Keep it short</p>' );

		// Single-line text fields never carry a counter.
		$text   = FieldTypeRegistry::get( 'text' );
		$single = [
			'id'    => 'f_t',
			'type'  => 'text',
			'label' => 'T',
		];

		expect( $text->render( $single, '', null, '7' ) )
			->not->toContain( 'relevant-forms-char-count' );
	}
);

it(
	'renders section separators without a value',
	function () {
		Monkey\Functions\when( 'esc_html' )->returnArg();
		Monkey\Functions\when( 'esc_attr' )->returnArg();

		$type = FieldTypeRegistry::get( 'section' );

		// Sections carry no value: nothing to validate or store.
		expect( $type->validate( [], '' ) )->toBeNull();
		expect( $type->sanitize( [], '' ) )->toBeNull();

		$markup = $type->render(
			[
				'id'    => 'f_sec',
				'type'  => 'section',
				'label' => 'Billing details',
			],
			null,
			null,
			'7'
		);

		expect( $markup )->toContain( 'relevant-forms-field--section' );
		expect( $markup )->toContain( '<h3 class="relevant-forms-section-title">Billing details</h3>' );
		expect( $markup )->toContain( '<hr class="relevant-forms-section-separator">' );

		// Without a label it is a bare separator.
		$bare = $type->render(
			[
				'id'    => 'f_sec',
				'type'  => 'section',
				'label' => '',
			],
			null,
			null,
			'7'
		);

		expect( $bare )->not->toContain( 'relevant-forms-section-title' );
		expect( $bare )->toContain( '<hr class="relevant-forms-section-separator">' );
	}
);

it(
	'renders page breaks as value-less layout markers',
	function () {
		$type = FieldTypeRegistry::get( 'pagebreak' );

		// Page breaks carry no value: nothing to validate or store.
		expect( $type->validate( [], '' ) )->toBeNull();
		expect( $type->sanitize( [], '' ) )->toBeNull();

		$markup = $type->render(
			[
				'id'    => 'f_break',
				'type'  => 'pagebreak',
				'label' => '',
			],
			null,
			null,
			'7'
		);

		expect( $markup )->toContain( '<hr class="relevant-forms-pagebreak" role="separator">' );
	}
);

it(
	'renders entry previews with value rows, notice and style',
	function () {
		Monkey\Functions\when( 'esc_attr' )->returnArg();
		Monkey\Functions\when( 'esc_html' )->returnArg();
		Monkey\Functions\when( 'esc_attr__' )->returnArg();
		Monkey\Functions\when( 'esc_html__' )->returnArg();
		Monkey\Functions\when( '__' )->returnArg();

		$type = FieldTypeRegistry::get( 'entry-preview' );

		// Previews carry no value: nothing to validate or store.
		expect( $type->validate( [], '' ) )->toBeNull();
		expect( $type->sanitize( [], '' ) )->toBeNull();

		$markup = $type->render(
			[
				'id'            => 'f_preview',
				'type'          => 'entry-preview',
				'label'         => 'Review',
				'notice_enable' => true,
				'notice'        => 'Check your answers',
				'style'         => 'table_compact',
				'preview_rows'  => [
					[
						'id'    => 'f_a',
						'label' => 'Name',
						'value' => 'Ada',
					],
					[
						'id'    => 'f_b',
						'label' => 'Email',
						'value' => '',
					],
					[
						'id'             => 'f_c',
						'label'          => 'City',
						'value'          => 'Rome',
						'hidden_by_rule' => true,
					],
				],
			],
			null,
			null,
			'7'
		);

		expect( $markup )->toContain( 'relevant-forms-entry-preview--table_compact' );
		expect( $markup )->toContain( '<h3 class="relevant-forms-preview-heading">Review</h3>' );
		expect( $markup )->toContain( '<p class="relevant-forms-preview-notice">Check your answers</p>' );

		// Filled rows show; empty and rule-hidden rows start hidden.
		expect( $markup )->toContain( 'data-rf-preview-row="f_a">' );
		expect( $markup )->not->toContain( 'data-rf-preview-row="f_a" hidden' );
		expect( $markup )->toContain( 'data-rf-preview-row="f_b" hidden' );
		expect( $markup )->toContain( 'data-rf-preview-row="f_c" hidden' );

		// One row is filled: the empty state stays hidden.
		expect( $markup )->toContain( 'data-rf-preview-empty hidden' );

		// Disabled notice, unknown style and no filled rows.
		$bare = $type->render(
			[
				'id'            => 'f_preview',
				'type'          => 'entry-preview',
				'notice_enable' => false,
				'style'         => 'wild',
				'preview_rows'  => [
					[
						'id'    => 'f_a',
						'label' => 'Name',
						'value' => '',
					],
				],
			],
			null,
			null,
			'7'
		);

		expect( $bare )->toContain( 'relevant-forms-entry-preview--basic' );
		expect( $bare )->not->toContain( 'relevant-forms-preview-notice' );
		expect( $bare )->not->toContain( 'relevant-forms-preview-heading' );
		expect( $bare )->toContain( 'data-rf-preview-empty>' );

		// Enabled but empty notice: the default message takes over.
		$default = $type->render(
			[
				'id'            => 'f_preview',
				'type'          => 'entry-preview',
				'notice_enable' => true,
				'notice'        => '',
			],
			null,
			null,
			'7'
		);

		expect( $default )->toContain( 'Please review your answers before submitting' );
	}
);

it(
	'renders content blocks with sanitized html and an optional heading',
	function () {
		Monkey\Functions\when( 'esc_html' )->returnArg();
		Monkey\Functions\when( 'wp_kses_post' )->returnArg();
		Monkey\Functions\when( 'wpautop' )->returnArg();

		$type = FieldTypeRegistry::get( 'content' );

		// Content blocks carry no value: nothing to validate or store.
		expect( $type->validate( [], '' ) )->toBeNull();
		expect( $type->sanitize( [], '' ) )->toBeNull();

		$markup = $type->render(
			[
				'id'      => 'f_content',
				'type'    => 'content',
				'label'   => 'Payment details',
				'content' => '<h4>Payment</h4>',
			],
			null,
			null,
			'7'
		);

		expect( $markup )->toContain( 'relevant-forms-field--content' );
		expect( $markup )->toContain( '<h3 class="relevant-forms-content-title">Payment details</h3>' );
		expect( $markup )->toContain( '<div class="relevant-forms-content"><h4>Payment</h4></div>' );

		// A fully empty block renders nothing at all.
		expect(
			$type->render(
				[
					'id'   => 'f',
					'type' => 'content',
				],
				null,
				null,
				'7'
			)
		)->toBe( '' );
	}
);
