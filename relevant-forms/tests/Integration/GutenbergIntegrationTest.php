<?php
/**
 * Gutenberg block integration tests: registration, shortcode parity of the
 * render callback and empty output without a renderable form.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Forms\FormRenderer;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Integrations\Gutenberg\GutenbergIntegration;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class GutenbergIntegrationTest extends TestCase {

	/**
	 * The block, its editor script and the front-end stylesheet are
	 * registered.
	 */
	public function testTheBlockIsRegistered(): void {
		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( GutenbergIntegration::BLOCK_NAME ) );
		$this->assertTrue( wp_script_is( 'relevant-forms-block-editor', 'registered' ) );
		$this->assertTrue( wp_style_is( 'relevant-forms', 'registered' ) );
	}

	/**
	 * Rendering the block matches the shortcode renderer except for the
	 * microtime anti-spam marker; the block output is wrapped in the
	 * standard block wrapper.
	 */
	public function testTheBlockRendersTheFormLikeTheShortcode(): void {
		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Contact' )->withFields(
			[
				[
					'id'       => 'f_name',
					'type'     => 'text',
					'label'    => 'Name',
					'required' => true,
				],
			]
		);

		$formId = $repository->create( 'Contact', $schema, 'published' );

		$markup = do_blocks( '<!-- wp:relevant-forms/form-selector {"formId":' . $formId . '} /-->' );

		$normalize = static fn ( string $html ): string => (string) preg_replace(
			'/name="_relevant_started_at" value="[^"]+"/',
			'name="_relevant_started_at"',
			$html
		);

		$this->assertStringContainsString( $normalize( FormRenderer::render( $formId ) ), $normalize( $markup ) );
		$this->assertStringContainsString( 'wp-block-relevant-forms-form-selector', $markup );
		$this->assertStringContainsString( 'relevant_field_f_name', $markup );
	}

	/**
	 * The block declares the style panels (block supports) and the wrapper
	 * carries them: colors, typography, spacing and border land on the
	 * block wrapper element server-side.
	 */
	public function testTheBlockDeclaresTheStyleSupportsAndRendersTheWrapper(): void {
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( GutenbergIntegration::BLOCK_NAME );

		$this->assertNotNull( $block_type );
		$this->assertTrue( $block_type->supports['color']['text'] );
		$this->assertTrue( $block_type->supports['color']['background'] );
		$this->assertTrue( $block_type->supports['typography']['fontSize'] );
		$this->assertTrue( $block_type->supports['spacing']['padding'] );
		$this->assertTrue( $block_type->supports['spacing']['margin'] );
		$this->assertTrue( $block_type->supports['__experimentalBorder']['radius'] );
		$this->assertTrue( $block_type->supports['__experimentalBorder']['color'] );
		$this->assertTrue( $block_type->supports['__experimentalBorder']['width'] );
		$this->assertTrue( $block_type->supports['__experimentalBorder']['style'] );

		$repository = new FormRepository();
		$formId     = $repository->create( 'Contact', FormSchema::empty( 'Contact' ), 'published' );

		$markup = do_blocks(
			'<!-- wp:relevant-forms/form-selector {"formId":' . $formId . ',"style":{"color":{"text":"#ff0000","background":"#f5f5f5"},"border":{"radius":"16px","width":"2px","style":"solid","color":"#333333"},"spacing":{"padding":{"top":"8px","left":"8px"}},"typography":{"fontSize":"15px"}},"className":"custom-x"} /-->'
		);

		// The standard block wrapper class (generated classname support) and
		// our embed class.
		$this->assertMatchesRegularExpression( '/<div [^>]*class="[^"]*wp-block-relevant-forms-form-selector[^"]*"/', $markup );
		$this->assertStringContainsString( 'relevant-forms-embed', $markup );
		$this->assertStringContainsString( 'custom-x', $markup );

		// The support styles are applied to the wrapper for the front end.
		$this->assertMatchesRegularExpression( '/style="[^"]*color:\s*(#ff0000|rgb\(255,\s*0,\s*0\))/', $markup );
		$this->assertMatchesRegularExpression( '/style="[^"]*background-color:\s*(#f5f5f5|rgb\(245,\s*245,\s*245\))/', $markup );
		$this->assertMatchesRegularExpression( '/style="[^"]*border-radius:\s*16px/', $markup );
		$this->assertMatchesRegularExpression( '/style="[^"]*border-width:\s*2px/', $markup );
		$this->assertMatchesRegularExpression( '/style="[^"]*border-color:\s*(#333333|rgb\(51,\s*51,\s*51\))/', $markup );
		$this->assertMatchesRegularExpression( '/style="[^"]*padding-top:\s*8px/', $markup );
		$this->assertMatchesRegularExpression( '/style="[^"]*font-size:\s*15px/', $markup );

		$this->assertStringContainsString( '<form', $markup );
	}

	/**
	 * The stylesheet bridges text color and font size from the wrapper onto
	 * the form controls (they do not inherit either by default).
	 */
	public function testTheStylesheetBridgesTextColorAndFontSizeToTheControls(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file, not a remote URL.
		$css = file_get_contents( RELEVANT_FORMS_PATH . 'assets/css/frontend.css' );

		$this->assertIsString( $css );
		$this->assertStringContainsString( '.wp-block-relevant-forms-form-selector .relevant-forms-field :is(input, select, textarea)', $css );
		$this->assertStringContainsString( 'color: inherit;', $css );
		$this->assertStringContainsString( 'font-size: inherit;', $css );
	}

	/**
	 * Without a form — or for draft/unknown ids — the block renders nothing.
	 */
	public function testTheBlockRendersNothingWithoutARenderableForm(): void {
		$this->assertStringNotContainsString( '<form', do_blocks( '<!-- wp:relevant-forms/form-selector /-->' ) );

		$repository = new FormRepository();
		$draft      = $repository->create( 'Sketch', FormSchema::empty( 'Sketch' ), 'draft' );

		$this->assertStringNotContainsString(
			'<form',
			do_blocks( '<!-- wp:relevant-forms/form-selector {"formId":' . $draft . '} /-->' )
		);
		$this->assertStringNotContainsString(
			'<form',
			do_blocks( '<!-- wp:relevant-forms/form-selector {"formId":999999} /-->' )
		);
	}

	/**
	 * The "Button styles" controls override the theme's button styling for
	 * this embed: the values render as a scoped stylesheet around the form,
	 * with a deterministic class.
	 */
	public function testTheBlockRendersTheButtonStyleOverrides(): void {
		$repository = new FormRepository();
		$formId     = $repository->create( 'Contact', FormSchema::empty( 'Contact' ), 'published' );

		$markup = do_blocks(
			'<!-- wp:relevant-forms/form-selector {"formId":' . $formId . ',"buttonBgColor":"#112233","buttonTextColor":"#ffffff","buttonRadius":"8px","buttonPadding":{"top":"12px","right":"24px","bottom":"12px","left":"24px"}} /-->'
		);

		$this->assertMatchesRegularExpression( '/<style>\.rf-gb-[0-9a-f]{12} \.relevant-forms \.wp-element-button\{/', $markup );
		$this->assertStringContainsString( 'background-color:#112233;', $markup );
		$this->assertStringContainsString( 'color:#ffffff;', $markup );
		$this->assertStringContainsString( 'border-radius:8px;', $markup );
		$this->assertStringContainsString( 'padding:12px 24px 12px 24px;', $markup );
		$this->assertMatchesRegularExpression( '/<div class="rf-gb-[0-9a-f]{12}">/', $markup );
		$this->assertStringContainsString( '<form class="relevant-forms"', $markup );

		// Partially set padding sides fill with zero so the shorthand stays
		// valid.
		$partial = do_blocks(
			'<!-- wp:relevant-forms/form-selector {"formId":' . $formId . ',"buttonPadding":{"top":"12px"}} /-->'
		);

		$this->assertStringContainsString( 'padding:12px 0 0 0;', $partial );
	}

	/**
	 * Without any button value the block renders the shortcode-identical
	 * markup: no stylesheet, no extra wrapper.
	 */
	public function testTheButtonStyleOverridesAreSkippedWithoutValues(): void {
		$repository = new FormRepository();
		$formId     = $repository->create( 'Contact', FormSchema::empty( 'Contact' ), 'published' );

		$markup = do_blocks( '<!-- wp:relevant-forms/form-selector {"formId":' . $formId . '} /-->' );

		$this->assertStringNotContainsString( '<style>', $markup );
		$this->assertStringNotContainsString( 'rf-gb-', $markup );
		$this->assertStringContainsString( '<form class="relevant-forms"', $markup );
	}

	/**
	 * Hostile button attribute values are dropped; valid values in the same
	 * form still render.
	 */
	public function testHostileButtonAttributeValuesAreDropped(): void {
		$repository = new FormRepository();
		$formId     = $repository->create( 'Contact', FormSchema::empty( 'Contact' ), 'published' );

		$markup = do_blocks(
			'<!-- wp:relevant-forms/form-selector {"formId":' . $formId . ',"buttonBgColor":"red;} .evil{color:blue","buttonRadius":"8"} /-->'
		);

		$this->assertStringContainsString( 'border-radius:8px;', $markup );
		$this->assertStringNotContainsString( 'background-color:', $markup );
		$this->assertStringNotContainsString( '.evil', $markup );
	}
}
