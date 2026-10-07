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

	/**
	 * The widget declares a Style tab section whose controls target the
	 * form's input fields through the shared selector (the widget class can
	 * only be loaded with Elementor present, so this is a static guard).
	 */
	public function testTheWidgetDeclaresFieldStyleControls(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file, not a remote URL.
		$file = file_get_contents( RELEVANT_FORMS_PATH . 'src/Integrations/Elementor/FormWidget.php' );

		$this->assertIsString( $file );
		$this->assertStringContainsString( "'Field styles'", $file );
		$this->assertStringContainsString( 'FieldSelectors::FIELDS', $file );
		$this->assertStringContainsString( 'Group_Control_Border', $file );
		$this->assertStringContainsString( 'Group_Control_Typography', $file );
		$this->assertStringContainsString( 'Group_Control_Box_Shadow', $file );
		$this->assertStringContainsString( 'field_padding', $file );
		$this->assertStringContainsString( 'field_radius', $file );
		$this->assertStringContainsString( '{{WRAPPER}}', $file );
	}

	/**
	 * The widget declares a "Button styles" section whose controls target
	 * the form's buttons through the shared selector (static guard — the
	 * widget class can only be loaded with Elementor present).
	 */
	public function testTheWidgetDeclaresButtonStyleControls(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file, not a remote URL.
		$file = file_get_contents( RELEVANT_FORMS_PATH . 'src/Integrations/Elementor/FormWidget.php' );

		$this->assertIsString( $file );
		$this->assertStringContainsString( "'Button styles'", $file );
		$this->assertStringContainsString( 'FieldSelectors::BUTTONS', $file );
		$this->assertStringContainsString( 'relevant_form_button_style', $file );
		$this->assertStringContainsString( 'register_button_style_controls', $file );
		$this->assertStringContainsString( 'button_bg_color', $file );
		$this->assertStringContainsString( 'button_text_color', $file );
		$this->assertStringContainsString( 'button_radius', $file );
		$this->assertStringContainsString( 'button_padding', $file );
	}

	/**
	 * The widget declares a "Text styles" section with one tab per text group
	 * — field labels, captions and option labels — each with its own color
	 * control and typography group, so a radio option does not have to look
	 * like the field name above it.
	 */
	public function testTheWidgetDeclaresTextStyleControls(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file, not a remote URL.
		$file = file_get_contents( RELEVANT_FORMS_PATH . 'src/Integrations/Elementor/FormWidget.php' );

		$this->assertIsString( $file );
		$this->assertStringContainsString( "'Text styles'", $file );
		$this->assertStringContainsString( 'FieldSelectors::LABELS', $file );
		$this->assertStringContainsString( 'FieldSelectors::CAPTIONS', $file );
		$this->assertStringContainsString( 'FieldSelectors::OPTIONS', $file );
		$this->assertStringContainsString( 'relevant_form_text_style', $file );
		$this->assertStringContainsString( 'register_text_style_controls', $file );
		$this->assertStringContainsString( 'start_controls_tabs', $file );
		$this->assertStringContainsString( "'Field labels'", $file );
		$this->assertStringContainsString( "'Field captions'", $file );
		$this->assertStringContainsString( "'Option labels'", $file );
		$this->assertStringContainsString( '$group . \'_style_color\'', $file );
		$this->assertStringContainsString( '$group . \'_style_typography\'', $file );

		// Registered between the field and button sections.
		$this->assertLessThan(
			(int) strpos( $file, 'register_button_style_controls();' ),
			(int) strpos( $file, 'register_text_style_controls();' )
		);
		$this->assertGreaterThan(
			(int) strpos( $file, 'register_field_style_controls();' ),
			(int) strpos( $file, 'register_text_style_controls();' )
		);
	}
}
