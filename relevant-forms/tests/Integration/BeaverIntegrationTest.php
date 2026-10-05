<?php
/**
 * Beaver Builder integration tests: option list, shortcode parity of the
 * render path and the inert-without-Beaver guarantee.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Forms\FormRenderer;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Integrations\BeaverBuilder\BeaverIntegration;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class BeaverIntegrationTest extends TestCase {

	/**
	 * The module picker leads with the "Select a form" placeholder.
	 */
	public function testFormChoicesPrependThePlaceholder(): void {
		$repository = new FormRepository();
		$published  = $repository->create( 'Contact', FormSchema::empty( 'Contact' ), 'published' );

		$choices = BeaverIntegration::formChoices();

		$this->assertSame( 'Select a form', $choices[''] );
		$this->assertSame( 'Contact', $choices[ $published ] );
	}

	/**
	 * Without forms the choices are the empty hint, not a broken select.
	 */
	public function testFormChoicesShowTheEmptyHintWhenNoFormsExist(): void {
		$choices = BeaverIntegration::formChoices();

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

		$html = BeaverIntegration::renderForm( $formId );

		$normalize = static fn ( string $markup ): string => (string) preg_replace(
			'/name="_relevant_started_at" value="[^"]+"/',
			'name="_relevant_started_at"',
			$markup
		);

		$this->assertSame( $normalize( FormRenderer::render( $formId ) ), $normalize( $html ) );
		$this->assertStringContainsString( 'f_name', $html );
		$this->assertSame( '', BeaverIntegration::renderForm( 0 ) );
	}

	/**
	 * Without Beaver Builder the hook is registered but loading is a no-op:
	 * no Beaver Builder class is pulled in and nothing is registered.
	 */
	public function testTheIntegrationStaysInertWithoutBeaverBuilder(): void {
		$this->assertNotFalse( has_action( 'init', [ BeaverIntegration::class, 'load' ] ) );

		BeaverIntegration::load();

		$this->assertFalse( class_exists( '\FLBuilder' ) );
		$this->assertFalse( class_exists( '\FLBuilderModuleType' ) );
	}
}
