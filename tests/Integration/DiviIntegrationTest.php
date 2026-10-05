<?php
/**
 * Divi integration integration tests: module choices, shortcode parity of the
 * render path, and the inert-without-Divi guarantee.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Forms\FormRenderer;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Integrations\Divi\DiviIntegration;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class DiviIntegrationTest extends TestCase {

	/**
	 * The form picker lists published and draft forms, drafts labelled, and
	 * hides archived ones.
	 */
	public function testFormChoicesListPublishedAndDraftFormsButNotArchived(): void {
		$repository = new FormRepository();

		$published = $repository->create( 'Contact', FormSchema::empty( 'Contact' ), 'published' );
		$draft     = $repository->create( 'Sketch', FormSchema::empty( 'Sketch' ), 'draft' );
		$archived  = $repository->create( 'Old', FormSchema::empty( 'Old' ), 'published' );
		$repository->archive( $archived );

		$choices = DiviIntegration::formChoices();

		$this->assertSame( 'Contact', $choices[ $published ] );
		$this->assertSame( 'Sketch (' . __( 'draft', 'relevant-forms' ) . ')', $choices[ $draft ] );
		$this->assertArrayNotHasKey( $archived, $choices );
	}

	/**
	 * With no forms at all the picker explains where to create one.
	 */
	public function testFormChoicesOfferAPlaceholderWhenNoFormsExist(): void {
		$choices = DiviIntegration::formChoices();

		$this->assertSame( [ '' ], array_keys( $choices ) );
		$this->assertStringContainsString( 'No forms yet', implode( ' ', $choices ) );
	}

	/**
	 * Rendering matches the shortcode path byte for byte.
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

		$html = DiviIntegration::renderForm( $formId );

		// The renderer stamps a microtime anti-spam marker; everything else
		// must match the renderer byte for byte.
		$normalize = static fn ( string $markup ): string => (string) preg_replace(
			'/name="_relevant_started_at" value="[^"]+"/',
			'name="_relevant_started_at"',
			$markup
		);

		$this->assertSame( $normalize( FormRenderer::render( $formId ) ), $normalize( $html ) );
		$this->assertStringContainsString( 'f_name', $html );

		$this->assertSame( '', DiviIntegration::renderForm( 0 ) );
		$this->assertSame( '', DiviIntegration::renderForm( 999999 ) );
	}

	/**
	 * Draft forms have no front-end output, mirroring the shortcode.
	 */
	public function testRenderFormOutputsNothingForDraftForms(): void {
		$repository = new FormRepository();
		$draft      = $repository->create( 'Sketch', FormSchema::empty( 'Sketch' ), 'draft' );

		$this->assertSame( '', DiviIntegration::renderForm( $draft ) );
	}

	/**
	 * Without Divi installed the builder hook is registered but firing it is
	 * a no-op: nothing loads and the module class stays undefined.
	 */
	public function testTheIntegrationStaysInertWithoutDivi(): void {
		$this->assertNotFalse( has_action( 'et_builder_ready', [ DiviIntegration::class, 'loadModule' ] ) );

		do_action( 'et_builder_ready' );

		$this->assertFalse( class_exists( 'RelevantForms\Integrations\Divi\FormModule' ) );
	}
}
