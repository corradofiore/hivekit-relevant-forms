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
}
