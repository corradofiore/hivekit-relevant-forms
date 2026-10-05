<?php
/**
 * Bricks integration tests: control options, shortcode parity of the render
 * path and the inert-without-Bricks guarantee.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Forms\FormRenderer;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Integrations\Bricks\BricksIntegration;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class BricksIntegrationTest extends TestCase {

	/**
	 * The element control leads with the "Select a form" placeholder.
	 */
	public function testFormChoicesPrependThePlaceholder(): void {
		$repository = new FormRepository();
		$published  = $repository->create( 'Contact', FormSchema::empty( 'Contact' ), 'published' );

		$choices = BricksIntegration::formChoices();

		$this->assertSame( 'Select a form', $choices[''] );
		$this->assertSame( 'Contact', $choices[ $published ] );
	}

	/**
	 * Without forms the choices are the empty hint, not a broken select.
	 */
	public function testFormChoicesShowTheEmptyHintWhenNoFormsExist(): void {
		$choices = BricksIntegration::formChoices();

		$this->assertSame( [ '' ], array_keys( $choices ) );
		$this->assertStringContainsString( 'No forms yet', implode( ' ', $choices ) );
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

		$html = BricksIntegration::renderForm( $formId );

		$normalize = static fn ( string $markup ): string => (string) preg_replace(
			'/name="_relevant_started_at" value="[^"]+"/',
			'name="_relevant_started_at"',
			$markup
		);

		$this->assertSame( $normalize( FormRenderer::render( $formId ) ), $normalize( $html ) );
		$this->assertStringContainsString( 'f_name', $html );
		$this->assertSame( '', BricksIntegration::renderForm( 0 ) );
	}

	/**
	 * Without Bricks the hook is registered but loading is a no-op: the
	 * element class stays undefined.
	 */
	public function testTheIntegrationStaysInertWithoutBricks(): void {
		$this->assertNotFalse( has_action( 'init', [ BricksIntegration::class, 'load' ] ) );

		BricksIntegration::load();

		$this->assertFalse( class_exists( 'RelevantForms\Integrations\Bricks\FormElement' ) );
	}
}
