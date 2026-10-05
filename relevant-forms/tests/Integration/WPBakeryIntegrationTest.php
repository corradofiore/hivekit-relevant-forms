<?php
/**
 * WPBakery integration tests: dropdown mapping, duplicate-label handling and
 * the inert-without-WPBakery guarantee.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Integrations\WPBakery\WPBakeryIntegration;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class WPBakeryIntegrationTest extends TestCase {

	/**
	 * The dropdown maps labels to form IDs (WPBakery order) and drafts are
	 * labelled.
	 */
	public function testElementOptionsMapLabelsToIds(): void {
		$repository = new FormRepository();

		$contact = $repository->create( 'Contact', FormSchema::empty( 'Contact' ), 'published' );
		$draft   = $repository->create( 'Sketch', FormSchema::empty( 'Sketch' ), 'draft' );

		$options = WPBakeryIntegration::elementOptions();

		$this->assertSame( (string) $contact, $options['Contact'] );
		$this->assertSame( (string) $draft, $options[ 'Sketch (' . __( 'draft', 'relevant-forms' ) . ')' ] );
		$this->assertArrayNotHasKey( '', $options );
	}

	/**
	 * Same-named forms both stay selectable, disambiguated by ID.
	 */
	public function testElementOptionsDisambiguateDuplicateLabels(): void {
		$repository = new FormRepository();

		$first  = $repository->create( 'Contact', FormSchema::empty( 'Contact' ), 'published' );
		$second = $repository->create( 'Contact', FormSchema::empty( 'Contact' ), 'published' );

		$options = WPBakeryIntegration::elementOptions();

		$this->assertCount( 2, $options );
		$this->assertSame( (string) $first, $options[ 'Contact (ID ' . $first . ')' ] );
		$this->assertSame( (string) $second, $options[ 'Contact (ID ' . $second . ')' ] );
	}

	/**
	 * With no forms at all the dropdown is empty (the no-forms hint is not a
	 * selectable form).
	 */
	public function testElementOptionsSkipTheNoFormsPlaceholder(): void {
		$this->assertSame( [], WPBakeryIntegration::elementOptions() );
	}

	/**
	 * Without WPBakery the hook is registered but firing it is a no-op.
	 */
	public function testTheIntegrationStaysInertWithoutWPBakery(): void {
		$this->assertNotFalse( has_action( 'vc_before_init', [ WPBakeryIntegration::class, 'registerElement' ] ) );

		do_action( 'vc_before_init' );

		$this->assertFalse( function_exists( 'vc_map' ) );
	}
}
