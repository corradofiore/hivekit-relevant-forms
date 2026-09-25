<?php
/**
 * Shortcode rendering integration tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Assets\FrontendAssets;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormRenderer;
use RelevantForms\Forms\FormSchema;

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

		$html = do_shortcode( '[relevant_form id="' . $id . '"]' );

		$this->assertStringContainsString( 'class="relevant-forms"', $html );
		$this->assertStringContainsString( 'name="relevant_field_f_name"', $html );
		$this->assertStringContainsString( '_relevant_form_nonce', $html );
		$this->assertStringContainsString( '_relevant_hp', $html );
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

		$html = do_shortcode( '[relevant_form id="' . $limited . '"]' );

		$this->assertStringContainsString(
			'<p class="relevant-forms-char-count" data-rf-count-for="relevant-field-' . $limited . '-f_msg">0/250</p>',
			$html
		);
		$this->assertTrue( wp_script_is( 'relevant-forms-counter', 'enqueued' ) );

		// Help text follows the counter, like in the editor preview.
		$this->assertStringContainsString(
			'0/250</p><p class="relevant-forms-help" id="relevant-field-' . $limited . '-f_msg-help">Keep it short</p>',
			$html
		);

		// Without a limit there is no total: hidden counter, no script.
		wp_dequeue_script( 'relevant-forms-counter' );

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

		$html = do_shortcode( '[relevant_form id="' . $unlimited . '"]' );

		$this->assertStringContainsString( ' hidden>0</p>', $html );
		$this->assertFalse( wp_script_is( 'relevant-forms-counter', 'enqueued' ) );
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

		$html    = do_shortcode( '[relevant_form id="' . $id . '"]' );
		$decoded = html_entity_decode( $html, ENT_QUOTES );

		// Both conditional fields carry their payload; only the field that
		// is hidden by default starts hidden.
		$this->assertSame( 2, substr_count( $decoded, 'data-rf-visibility=' ) );
		$this->assertSame( 1, substr_count( $decoded, ' hidden>' ) );
		$this->assertStringContainsString( 'data-rf-required="1" hidden>', $decoded );

		// The hidden required field renders without the required flag (it
		// would block a no-JS submission) but marks itself as required.
		$this->assertSame( 1, preg_match( '/<input[^>]*name="relevant_field_f_extra"[^>]*>/', $decoded, $matches ) );
		$this->assertStringNotContainsString( 'required', $matches[0] );

		// The script is enqueued lazily: only forms with rules load it.
		$this->assertTrue( wp_script_is( 'relevant-forms-conditional', 'enqueued' ) );

		wp_dequeue_script( 'relevant-forms-conditional' );

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

		do_shortcode( '[relevant_form id="' . $plain . '"]' );

		$this->assertFalse( wp_script_is( 'relevant-forms-conditional', 'enqueued' ) );
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

		$html = relevant_forms_render( $id );

		$this->assertStringContainsString( 'relevant-forms', $html );
	}

	/**
	 * Test unknown ids render nothing.
	 */
	public function testUnknownFormRendersNothing(): void {
		$this->assertSame( '', FormRenderer::render( 999999 ) );
	}

	/**
	 * Page breaks turn the markup into labelled step containers, load the
	 * navigation script and move the submit button into a dedicated row.
	 */
	public function testPaginatedFormsRenderAsSteps(): void {
		FrontendAssets::registerAssets();

		$repository = new FormRepository();

		$id = $repository->create(
			'Stepped Form',
			FormSchema::empty( 'Stepped Form' )->withFields(
				[
					[
						'id'    => 'f_name',
						'type'  => 'text',
						'label' => 'Name',
					],
					[
						'id'    => 'f_break_1',
						'type'  => 'pagebreak',
						'label' => 'Trip details',
					],
					[
						'id'    => 'f_city',
						'type'  => 'text',
						'label' => 'City',
					],
					[
						'id'    => 'f_break_2',
						'type'  => 'pagebreak',
						'label' => '',
					],
					[
						'id'    => 'f_email',
						'type'  => 'email',
						'label' => 'Email',
					],
				]
			),
			'published'
		);

		$html = do_shortcode( '[relevant_form id="' . $id . '"]' );

		$this->assertStringContainsString( 'data-rf-page="1"', $html );
		$this->assertStringContainsString( 'data-rf-page="2"', $html );
		$this->assertStringContainsString( 'data-rf-page="3"', $html );
		$this->assertStringContainsString( 'Step 1 of 3', $html );
		$this->assertStringContainsString( 'Step 2 of 3', $html );
		$this->assertStringContainsString( 'Step 3 of 3', $html );

		// The labeled break titles its page; the unlabeled one stays bare.
		$this->assertStringContainsString( '<h3 class="relevant-forms-page-title">Trip details</h3>', $html );
		$this->assertSame( 1, substr_count( $html, 'relevant-forms-page-title' ) );

		// Every page after the first opens with the break divider.
		$this->assertSame( 2, substr_count( $html, 'relevant-forms-pagebreak' ) );

		$this->assertStringContainsString( 'data-rf-submit-row', $html );
		$this->assertStringContainsString( 'data-rf-page-prev="Previous" data-rf-page-next="Next" data-rf-page-step="Step %1$d of %2$d"', $html );
		$this->assertTrue( wp_script_is( 'relevant-forms-pages', 'enqueued' ) );

		// Fields arrive in page order and the submit row closes the last page.
		$this->assertLessThan( strpos( $html, 'data-rf-page="2"' ), strpos( $html, 'name="relevant_field_f_name"' ) );
		$this->assertLessThan( strpos( $html, 'data-rf-submit-row' ), strpos( $html, 'name="relevant_field_f_email"' ) );

		wp_dequeue_script( 'relevant-forms-pages' );
	}

	/**
	 * Forms without usable breaks keep the plain single-page markup: no
	 * containers, no step indicators and no navigation script.
	 */
	public function testSinglePageFormsKeepPlainMarkup(): void {
		FrontendAssets::registerAssets();

		$repository = new FormRepository();

		$plain = $repository->create(
			'Plain Form',
			FormSchema::empty( 'Plain Form' )->withFields(
				[
					[
						'id'    => 'f_name',
						'type'  => 'text',
						'label' => 'Name',
					],
				]
			),
			'published'
		);

		$html = do_shortcode( '[relevant_form id="' . $plain . '"]' );

		$this->assertStringNotContainsString( 'data-rf-page=', $html );
		$this->assertStringNotContainsString( 'data-rf-submit-row', $html );
		$this->assertStringNotContainsString( 'data-rf-page-step', $html );
		$this->assertFalse( wp_script_is( 'relevant-forms-pages', 'enqueued' ) );

		// A trailing break with no fields after it merges away: still plain.
		$trailing = $repository->create(
			'Trailing Form',
			FormSchema::empty( 'Trailing Form' )->withFields(
				[
					[
						'id'    => 'f_name',
						'type'  => 'text',
						'label' => 'Name',
					],
					[
						'id'    => 'f_break',
						'type'  => 'pagebreak',
						'label' => 'Never shown',
					],
				]
			),
			'published'
		);

		$html = do_shortcode( '[relevant_form id="' . $trailing . '"]' );

		$this->assertStringNotContainsString( 'data-rf-page=', $html );
		$this->assertStringNotContainsString( 'Step 1', $html );
		$this->assertFalse( wp_script_is( 'relevant-forms-pages', 'enqueued' ) );
	}

	/**
	 * Entry previews render one row per data field above them, keep empty
	 * rows hidden, show the notice and load their sync script lazily.
	 */
	public function testEntryPreviewRendersRowsAndLoadsItsScript(): void {
		FrontendAssets::registerAssets();

		$repository = new FormRepository();

		$id = $repository->create(
			'Preview Form',
			FormSchema::empty( 'Preview Form' )->withFields(
				[
					[
						'id'    => 'f_name',
						'type'  => 'text',
						'label' => 'Name',
					],
					[
						'id'      => 'f_plan',
						'type'    => 'select',
						'label'   => 'Plan',
						'choices' => [ 'Starter|starter', 'Pro|pro' ],
					],
					[
						'id'            => 'f_preview',
						'type'          => 'entry-preview',
						'label'         => '',
						'notice_enable' => true,
						'notice'        => 'Check your answers',
						'style'         => 'table',
					],
				]
			),
			'published'
		);

		$html = do_shortcode( '[relevant_form id="' . $id . '"]' );

		$this->assertStringContainsString( 'data-rf-preview', $html );
		$this->assertStringContainsString( 'relevant-forms-entry-preview--table', $html );
		$this->assertStringContainsString( 'Check your answers', $html );
		$this->assertStringContainsString( 'data-rf-preview-row="f_name"', $html );
		$this->assertStringContainsString( 'data-rf-preview-row="f_plan"', $html );

		// The preview field itself and layout fields get no rows; without a
		// value every row starts hidden and the empty state is visible.
		$this->assertStringNotContainsString( 'data-rf-preview-row="f_preview"', $html );
		$this->assertStringContainsString( 'data-rf-preview-row="f_name" hidden', $html );
		$this->assertStringContainsString( 'data-rf-preview-empty>', $html );
		$this->assertStringNotContainsString( 'data-rf-preview-empty hidden', $html );

		$this->assertTrue( wp_script_is( 'relevant-forms-preview', 'enqueued' ) );

		wp_dequeue_script( 'relevant-forms-preview' );

		// Forms without a preview never load the script.
		$plain = $repository->create(
			'No Preview Form',
			FormSchema::empty( 'No Preview Form' )->withFields(
				[
					[
						'id'    => 'f_name',
						'type'  => 'text',
						'label' => 'Name',
					],
				]
			),
			'published'
		);

		do_shortcode( '[relevant_form id="' . $plain . '"]' );

		$this->assertFalse( wp_script_is( 'relevant-forms-preview', 'enqueued' ) );
	}
}
