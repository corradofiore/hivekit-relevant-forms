<?php
/**
 * Shortcode rendering integration tests.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Assets\FrontendAssets;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormRenderer;
use SentinelForms\Forms\FormSchema;

/**
 * Shortcode and rendering integration tests.
 *
 * @group integration
 */
final class ShortcodeRenderTest extends TestCase {

	/**
	 * Test rendering a published form via shortcode.
	 */
	public function testShortcodeRendersForm(): void {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Shortcode Form' )->withFields(
			[
				[
					'id'       => 'f_name',
					'type'     => 'text',
					'label'    => 'Name',
					'required' => true,
				],
			]
		);

		$id = $repository->create( 'Shortcode Form', $schema, 'published' );

		$html = do_shortcode( '[sentinel_form id="' . $id . '"]' );

		$this->assertStringContainsString( 'class="sentinel-forms"', $html );
		$this->assertStringContainsString( 'name="sentinel_field_f_name"', $html );
		$this->assertStringContainsString( '_sentinel_form_nonce', $html );
		$this->assertStringContainsString( '_sentinel_hp', $html );
	}

	/**
	 * Limited paragraph fields carry a realtime character counter and load
	 * its script; without a limit the counter stays hidden and is skipped.
	 */
	public function testLimitedTextareasRenderAndLoadTheCharacterCounter(): void {
		FrontendAssets::registerAssets();

		$repository = new FormRepository();

		$limited = $repository->create(
			'Counter Form',
			FormSchema::empty( 'Counter Form' )->withFields(
				[
					[
						'id'         => 'f_msg',
						'type'       => 'textarea',
						'label'      => 'Message',
						'max_length' => 250,
						'help_text'  => 'Keep it short',
					],
				]
			),
			'published'
		);

		$html = do_shortcode( '[sentinel_form id="' . $limited . '"]' );

		$this->assertStringContainsString(
			'<p class="sentinel-forms-char-count" data-sf-count-for="sentinel-field-' . $limited . '-f_msg">0/250</p>',
			$html
		);
		$this->assertTrue( wp_script_is( 'sentinel-forms-counter', 'enqueued' ) );

		// Help text follows the counter, like in the editor preview.
		$this->assertStringContainsString(
			'0/250</p><p class="sentinel-forms-help" id="sentinel-field-' . $limited . '-f_msg-help">Keep it short</p>',
			$html
		);

		// Without a limit there is no total: hidden counter, no script.
		wp_dequeue_script( 'sentinel-forms-counter' );

		$unlimited = $repository->create(
			'Unlimited Form',
			FormSchema::empty( 'Unlimited Form' )->withFields(
				[
					[
						'id'         => 'f_msg',
						'type'       => 'textarea',
						'label'      => 'Message',
						'max_length' => 0,
					],
				]
			),
			'published'
		);

		$html = do_shortcode( '[sentinel_form id="' . $unlimited . '"]' );

		$this->assertStringContainsString( ' hidden>0</p>', $html );
		$this->assertFalse( wp_script_is( 'sentinel-forms-counter', 'enqueued' ) );
	}

	/**
	 * Conditional fields render with their payload; the initial state
	 * follows the rules and un-required hidden fields remember that they
	 * are required. The script loads only for forms with rules.
	 */
	public function testConditionalFieldsCarryTheirVisibilityPayload(): void {
		FrontendAssets::registerAssets();

		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Conditional Form' )->withFields(
			[
				[
					'id'      => 'f_plan',
					'type'    => 'select',
					'label'   => 'Plan',
					'choices' => [
						[
							'label' => 'Free',
							'value' => 'free',
						],
						[
							'label' => 'Pro',
							'value' => 'pro',
						],
					],
				],
				[
					'id'         => 'f_extra',
					'type'       => 'text',
					'label'      => 'Extra',
					'required'   => true,
					'visibility' => [
						'default' => 'hidden',
						'groups'  => [
							[
								[
									'field'    => 'f_plan',
									'operator' => 'is',
									'value'    => 'pro',
								],
							],
						],
					],
				],
				[
					'id'         => 'f_note',
					'type'       => 'text',
					'label'      => 'Note',
					'visibility' => [
						'default' => 'shown',
						'groups'  => [
							[
								[
									'field'    => 'f_plan',
									'operator' => 'is',
									'value'    => 'pro',
								],
							],
						],
					],
				],
			]
		);

		$id = $repository->create( 'Conditional Form', $schema, 'published' );

		$html    = do_shortcode( '[sentinel_form id="' . $id . '"]' );
		$decoded = html_entity_decode( $html, ENT_QUOTES );

		// Both conditional fields carry their payload; only the field that
		// is hidden by default starts hidden.
		$this->assertSame( 2, substr_count( $decoded, 'data-sf-visibility=' ) );
		$this->assertSame( 1, substr_count( $decoded, ' hidden>' ) );
		$this->assertStringContainsString( 'data-sf-required="1" hidden>', $decoded );

		// The hidden required field renders without the required flag (it
		// would block a no-JS submission) but marks itself as required.
		$this->assertSame( 1, preg_match( '/<input[^>]*name="sentinel_field_f_extra"[^>]*>/', $decoded, $matches ) );
		$this->assertStringNotContainsString( 'required', $matches[0] );

		// The script is enqueued lazily: only forms with rules load it.
		$this->assertTrue( wp_script_is( 'sentinel-forms-conditional', 'enqueued' ) );

		wp_dequeue_script( 'sentinel-forms-conditional' );

		$plain = $repository->create(
			'Plain Form',
			FormSchema::empty( 'Plain Form' )->withFields(
				[
					[
						'id'    => 'f_a',
						'type'  => 'text',
						'label' => 'A',
					],
				]
			),
			'published'
		);

		do_shortcode( '[sentinel_form id="' . $plain . '"]' );

		$this->assertFalse( wp_script_is( 'sentinel-forms-conditional', 'enqueued' ) );
	}

	/**
	 * Test drafts render nothing.
	 */
	public function testDraftRendersNothing(): void {
		$repository = new FormRepository();
		$id         = $repository->create( 'Draft Form', FormSchema::empty( 'Draft Form' ) );

		$this->assertSame( '', FormRenderer::render( $id ) );
	}

	/**
	 * Test the PHP template function.
	 */
	public function testTemplateFunction(): void {
		$repository = new FormRepository();
		$id         = $repository->create( 'Fn Form', FormSchema::empty( 'Fn Form' ), 'published' );

		$html = sentinel_forms_render( $id );

		$this->assertStringContainsString( 'sentinel-forms', $html );
	}

	/**
	 * Test unknown ids render nothing.
	 */
	public function testUnknownFormRendersNothing(): void {
		$this->assertSame( '', FormRenderer::render( 999999 ) );
	}
}
