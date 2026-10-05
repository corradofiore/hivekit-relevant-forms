<?php
/**
 * Bricks element: "Relevant Form".
 *
 * Loaded by BricksIntegration::load() while Bricks is active, so the
 * \Bricks\Element parent only needs to exist at that point. The registration
 * call at the bottom of this file adds the element to the builder.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Bricks;

// Defence in depth: the autoloader may reach this file on sites without Bricks.
if ( ! class_exists( '\Bricks\Element' ) ) {
	return;
}

/**
 * Element definition: a form picker plus server-side rendering through the
 * same code path as the shortcode.
 */
final class FormElement extends \Bricks\Element {

	/**
	 * Element category shown in the builder panel.
	 *
	 * @var string
	 */
	public $category = 'general';

	/**
	 * Unique element identifier.
	 *
	 * @var string
	 */
	public $name = 'relevant-form';

	/**
	 * Element icon (Font Awesome 6).
	 *
	 * @var string
	 */
	public $icon = 'fas fa-wpforms';

	/**
	 * Element label.
	 */
	public function get_label(): string {
		return esc_html__( 'Relevant Form', 'relevant-forms' );
	}

	/**
	 * Search keywords.
	 *
	 * @return list<string>
	 */
	public function get_keywords(): array {
		return [ 'form', 'relevant', 'contact' ];
	}

	/**
	 * Element controls: pick the form to embed.
	 */
	public function set_controls(): void {
		$this->controls['formId'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Form', 'relevant-forms' ),
			'type'        => 'select',
			'options'     => BricksIntegration::formChoices(),
			'default'     => '',
			'description' => esc_html__( 'Only published forms are rendered on the front end.', 'relevant-forms' ),
		];
	}

	/**
	 * Front-end output: identical to the [relevant_form] shortcode.
	 */
	public function render(): void {
		$html = BricksIntegration::renderForm( (int) ( $this->settings['formId'] ?? 0 ) );

		if ( '' === $html ) {
			return;
		}

		$this->set_attribute( '_root', 'class', 'relevant-forms-bricks' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() escapes the attributes; $html is complete, escaped form markup.
		echo '<div ' . $this->render_attributes( '_root' ) . '>' . $html . '</div>';
	}
}

// Register the element with Bricks (name + class arguments speed up loading).
\Bricks\Elements::register_element( __FILE__, 'relevant-form', FormElement::class );
