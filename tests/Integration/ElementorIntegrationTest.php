<?php
/**
 * Elementor integration tests: widget option list, shortcode parity of the
 * render path and the inert-without-Elementor guarantee.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Forms\FormRenderer;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Integrations\Elementor\ElementorIntegration;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class ElementorIntegrationTest extends TestCase {

	/**
	 * The widget control options lead with the "Select a form" placeholder.
	 */
	public function testFormOptionsPrependThePlaceholder(): void {
		$repository = new FormRepository();

		$published = $repository->create( 'Contact', FormSchema::empty( 'Contact' ), 'published' );
		$draft     = $repository->create( 'Sketch', FormSchema::empty( 'Sketch' ), 'draft' );

		$options = ElementorIntegration::formOptions();

		$this->assertSame( 'Select a form', $options[''] );
		$this->assertSame( 'Contact', $options[ $published ] );
		$this->assertSame( 'Sketch (' . __( 'draft', 'relevant-forms' ) . ')', $options[ $draft ] );
		$this->assertSame( '', array_key_first( $options ) );
	}

	/**
	 * Without forms the options are the empty hint, not a broken select.
	 */
	public function testFormOptionsShowTheEmptyHintWhenNoFormsExist(): void {
		$options = ElementorIntegration::formOptions();

		$this->assertSame( [ '' ], array_keys( $options ) );
		$this->assertStringContainsString( 'No forms yet', implode( ' ', $options ) );
	}

	/**
	 * Rendering matches the shortcode path byte for byte apart from the
	 * microtime anti-spam marker.
	 */
	public function testRenderFormMatchesTheShortcodeRenderer(): void {
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

		$html = ElementorIntegration::renderForm( $formId );

		$normalize = static fn ( string $markup ): string => (string) preg_replace(
			'/name="_relevant_started_at" value="[^"]+"/',
			'name="_relevant_started_at"',
			$markup
		);

		$this->assertSame( $normalize( FormRenderer::render( $formId ) ), $normalize( $html ) );
		$this->assertStringContainsString( 'f_name', $html );
		$this->assertSame( '', ElementorIntegration::renderForm( 0 ) );
	}

	/**
	 * Without Elementor the hooks are registered but firing them is a no-op:
	 * the widget class stays undefined.
	 */
	public function testTheIntegrationStaysInertWithoutElementor(): void {
		$this->assertNotFalse( has_action( 'elementor/widgets/register', [ ElementorIntegration::class, 'registerWidget' ] ) );
		$this->assertNotFalse( has_action( 'elementor/elements/categories_registered', [ ElementorIntegration::class, 'registerCategory' ] ) );

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Elementor's public hook name.
		do_action( 'elementor/widgets/register' );
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Elementor's public hook name.
		do_action( 'elementor/elements/categories_registered' );

		$this->assertFalse( class_exists( 'RelevantForms\Integrations\Elementor\FormWidget' ) );
	}
}
