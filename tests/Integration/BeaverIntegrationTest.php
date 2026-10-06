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
use RelevantForms\Integrations\BeaverBuilder\ModuleStyles;
use RelevantForms\Integrations\Support\FieldSelectors;

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

	/**
	 * The Style tab offers the field styling controls: colors, border,
	 * rounded corners, padding and font size.
	 */
	public function testTheStyleTabOffersTheFieldStylingControls(): void {
		$tab = ModuleStyles::styleTab();

		$this->assertSame( 'Style', $tab['title'] );
		$this->assertArrayHasKey( 'field_styles', $tab['sections'] );
		$this->assertSame( 'Field styles', $tab['sections']['field_styles']['title'] );

		$fields = array_keys( $tab['sections']['field_styles']['fields'] );

		$this->assertSame(
			[
				'field_bg_color',
				'field_text_color',
				'field_placeholder_color',
				'field_border_width',
				'field_border_style',
				'field_border_color',
				'field_radius',
				'field_padding',
				'field_font_size',
			],
			$fields
		);
	}

	/**
	 * With style values set the module output is a scoped `<style>` block
	 * targeting the form's input fields (not the module wrapper) plus the
	 * wrapped form markup.
	 */
	public function testTheModuleRendersScopedFieldStyles(): void {
		$formId = $this->createFormWithOneTextField();

		$html = ModuleStyles::render(
			(object) [
				'form_id'                 => $formId,
				'field_bg_color'          => '#f5f5f5',
				'field_text_color'        => '#222222',
				'field_placeholder_color' => 'rgba(34, 34, 34, 0.6)',
				'field_border_width'      => '2',
				'field_border_color'      => '#333333',
				'field_radius'            => '16',
				'field_radius_unit'       => 'px',
				'field_padding_top'       => '8',
				'field_padding_right'     => '10',
				'field_padding_bottom'    => '8',
				'field_padding_left'      => '10',
				'field_padding_unit'      => 'px',
				'field_font_size'         => '15',
				'field_font_size_unit'    => 'px',
			]
		);

		// A deterministic per-instance scope wraps the form.
		$this->assertMatchesRegularExpression( '/<div class="rf-bb-[0-9a-f]{12}">/', $html );
		$this->assertStringContainsString( 'relevant-forms-field', $html );

		// The CSS targets the input fields, not the wrapper.
		$this->assertStringContainsString( '<style>', $html );
		$this->assertStringContainsString( '.rf-bb-', $html );
		$this->assertStringContainsString( FieldSelectors::FIELDS, $html );
		$this->assertStringContainsString( 'background-color:#f5f5f5;', $html );
		$this->assertStringContainsString( 'color:#222222;', $html );
		$this->assertStringContainsString( 'border-width:2px;border-style:solid;border-color:#333333;', $html );
		$this->assertStringContainsString( 'border-radius:16px;', $html );
		$this->assertStringContainsString( 'padding:8px 10px 8px 10px;', $html );
		$this->assertStringContainsString( 'font-size:15px;', $html );
		$this->assertStringContainsString( FieldSelectors::FIELDS_PLACEHOLDER, $html );
		$this->assertStringContainsString( 'color:rgba(34, 34, 34, 0.6);', $html );
	}

	/**
	 * Without any style value the module still renders the form — just no
	 * `<style>` block.
	 */
	public function testTheStyleBlockIsOmittedWithoutStyleValues(): void {
		$formId = $this->createFormWithOneTextField();

		$html = ModuleStyles::render( (object) [ 'form_id' => $formId ] );

		$this->assertStringNotContainsString( '<style>', $html );
		$this->assertMatchesRegularExpression( '/<div class="rf-bb-[0-9a-f]{12}">/', $html );
		$this->assertStringContainsString( 'relevant-forms-field', $html );
	}

	/**
	 * Color values that could break out of the declaration are dropped; the
	 * form still renders.
	 */
	public function testHostileColorValuesAreDropped(): void {
		$formId = $this->createFormWithOneTextField();

		$html = ModuleStyles::render(
			(object) [
				'form_id'        => $formId,
				'field_bg_color' => 'red;} .evil{color:blue',
			]
		);

		$this->assertStringNotContainsString( '<style>', $html );
		$this->assertStringNotContainsString( '.evil', $html );
		$this->assertStringContainsString( 'relevant-forms-field', $html );
	}

	/**
	 * No published form selected: no wrapper, no styles, no markup.
	 */
	public function testTheModuleRendersNothingWithoutAPublishedForm(): void {
		$this->assertSame( '', ModuleStyles::render( (object) [ 'form_id' => 0 ] ) );
		$this->assertSame( '', ModuleStyles::render( (object) [] ) );
	}

	/**
	 * The Style tab's "Button styles" section offers the button controls:
	 * colors, rounded corners and padding.
	 */
	public function testTheStyleTabOffersTheButtonStylingControls(): void {
		$tab = ModuleStyles::styleTab();

		$this->assertArrayHasKey( 'button_styles', $tab['sections'] );
		$this->assertSame( 'Button styles', $tab['sections']['button_styles']['title'] );

		$this->assertSame(
			[ 'button_bg_color', 'button_text_color', 'button_radius', 'button_padding' ],
			array_keys( $tab['sections']['button_styles']['fields'] )
		);
	}

	/**
	 * Button style values are emitted as one scoped rule targeting the
	 * form's buttons (`.relevant-forms .wp-element-button`), and they render
	 * without any field style being set.
	 */
	public function testTheModuleRendersScopedButtonStyles(): void {
		$formId = $this->createFormWithOneTextField();

		$html = ModuleStyles::render(
			(object) [
				'form_id'               => $formId,
				'button_bg_color'       => '#112233',
				'button_text_color'     => 'rgba(255, 255, 255, 0.9)',
				'button_radius'         => '8',
				'button_radius_unit'    => 'px',
				'button_padding_top'    => '12',
				'button_padding_right'  => '24',
				'button_padding_bottom' => '12',
				'button_padding_left'   => '24',
				'button_padding_unit'   => 'px',
			]
		);

		$this->assertMatchesRegularExpression( '/\.rf-bb-[0-9a-f]{12} \.relevant-forms \.wp-element-button\{/', $html );
		$this->assertStringContainsString( 'background-color:#112233;', $html );
		$this->assertStringContainsString( 'color:rgba(255, 255, 255, 0.9);', $html );
		$this->assertStringContainsString( 'border-radius:8px;', $html );
		$this->assertStringContainsString( 'padding:12px 24px 12px 24px;', $html );
		$this->assertStringContainsString( 'relevant-forms-field', $html );
	}

	/**
	 * Hostile button values are dropped exactly like the field ones: the
	 * valid radius still renders, the injection does not.
	 */
	public function testHostileButtonValuesAreDropped(): void {
		$formId = $this->createFormWithOneTextField();

		$html = ModuleStyles::render(
			(object) [
				'form_id'            => $formId,
				'button_bg_color'    => 'red;} .evil{color:blue',
				'button_radius'      => '8',
				'button_radius_unit' => 'px',
			]
		);

		$this->assertStringContainsString( 'border-radius:8px;', $html );
		$this->assertStringNotContainsString( 'background-color:', $html );
		$this->assertStringNotContainsString( '.evil', $html );
	}

	/**
	 * Create a published form with one text field for render tests.
	 *
	 * @return int Form ID.
	 */
	private function createFormWithOneTextField(): int {
		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Contact' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
			]
		);

		return $repository->create( 'Contact', $schema, 'published' );
	}
}
