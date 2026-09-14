<?php
/**
 * Shortcode rendering integration tests.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

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
