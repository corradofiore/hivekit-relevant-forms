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
	 * microtime anti-spam marker.
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

		$this->assertSame( $normalize( FormRenderer::render( $formId ) ), $normalize( $markup ) );
		$this->assertStringContainsString( 'relevant_field_f_name', $markup );
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
