<?php
/**
 * Elementor widget: "Relevant Form".
 *
 * Loaded by ElementorIntegration::registerWidget() while Elementor is active,
 * so the \Elementor\Widget_Base parent only needs to exist at that point.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Elementor;

// Defence in depth: the autoloader may reach this file on sites without Elementor.
if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * Widget definition: a form picker plus server-side rendering through the
 * same code path as the shortcode.
 */
final class FormWidget extends \Elementor\Widget_Base {

	/**
	 * Unique widget name.
	 */
	public function get_name(): string {
		return 'relevant-form';
	}

	/**
	 * Widget title shown in the panel.
	 */
	public function get_title(): string {
		return esc_html__( 'Relevant Form', 'relevant-forms' );
	}

	/**
	 * Panel icon.
	 */
	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}

	/**
	 * Widget categories.
	 *
	 * @return list<string>
	 */
	public function get_categories(): array {
		return [ 'relevant-forms' ];
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
	 * Widget controls: pick the form to embed.
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'relevant_form_section',
			[
				'label' => esc_html__( 'Form', 'relevant-forms' ),
			]
		);

		$this->add_control(
			'form_id',
			[
				'label'       => esc_html__( 'Form', 'relevant-forms' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => ElementorIntegration::formOptions(),
				'default'     => '',
				'description' => esc_html__( 'Only published forms are rendered on the front end.', 'relevant-forms' ),
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Front-end output: identical to the [relevant_form] shortcode.
	 */
	protected function render(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Complete, already-escaped form markup from FormRenderer.
		echo ElementorIntegration::renderForm( (int) $this->get_settings_for_display( 'form_id' ) );
	}
}
