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

use RelevantForms\Integrations\Support\FieldSelectors;

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
	 * Widget controls: pick the form to embed, then style its fields.
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

		$this->register_field_style_controls();
		$this->register_text_style_controls();
		$this->register_button_style_controls();
	}

	/**
	 * Style section: the controls target the form's input fields (not the
	 * widget wrapper), mirroring the design groups offered by the Divi and
	 * Beaver Builder integrations. Elementor writes the CSS from the
	 * `selectors` maps — no stylesheet of ours is involved.
	 */
	private function register_field_style_controls(): void {
		$fields = '{{WRAPPER}} ' . FieldSelectors::FIELDS;

		$this->start_controls_section(
			'relevant_form_field_style',
			[
				'label' => esc_html__( 'Field styles', 'relevant-forms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'name'     => 'field_typography',
				'selector' => $fields,
			]
		);

		$this->add_control(
			'field_text_color',
			[
				'label'     => esc_html__( 'Field text color', 'relevant-forms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					$fields => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'field_placeholder_color',
			[
				'label'     => esc_html__( 'Placeholder color', 'relevant-forms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					$fields . '::placeholder' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'field_bg_color',
			[
				'label'     => esc_html__( 'Field background', 'relevant-forms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					$fields => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			[
				'name'     => 'field_border',
				'selector' => $fields,
			]
		);

		$this->add_responsive_control(
			'field_radius',
			[
				'label'      => esc_html__( 'Rounded corners', 'relevant-forms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					$fields => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'field_padding',
			[
				'label'      => esc_html__( 'Padding', 'relevant-forms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', '%' ],
				'selectors'  => [
					$fields => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			[
				'name'     => 'field_box_shadow',
				'selector' => $fields,
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style section: the text outside the controls, styled per group — field
	 * labels, captions and option labels are configured independently, so a
	 * radio option does not have to look like the field name above it. Each
	 * tab offers a color control plus the full typography group (size,
	 * weight, line height, spacing, transform).
	 */
	private function register_text_style_controls(): void {
		$this->start_controls_section(
			'relevant_form_text_style',
			[
				'label' => esc_html__( 'Text styles', 'relevant-forms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$this->start_controls_tabs( 'relevant_form_text_style_tabs' );

		foreach (
			[
				'label'   => [
					'label'    => esc_html__( 'Field labels', 'relevant-forms' ),
					'selector' => FieldSelectors::LABELS,
				],
				'caption' => [
					'label'    => esc_html__( 'Field captions', 'relevant-forms' ),
					'selector' => FieldSelectors::CAPTIONS,
				],
				'option'  => [
					'label'    => esc_html__( 'Option labels', 'relevant-forms' ),
					'selector' => FieldSelectors::OPTIONS,
				],
			] as $group => $config
		) {
			$selector = '{{WRAPPER}} ' . $config['selector'];

			$this->start_controls_tab(
				'relevant_form_text_style_tab_' . $group,
				[ 'label' => $config['label'] ]
			);

			$this->add_control(
				$group . '_style_color',
				[
					'label'     => esc_html__( 'Text color', 'relevant-forms' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => [
						$selector => 'color: {{VALUE}};',
					],
				]
			);

			$this->add_group_control(
				\Elementor\Group_Control_Typography::get_type(),
				[
					'name'     => $group . '_style_typography',
					'selector' => $selector,
				]
			);

			$this->end_controls_tab();
		}

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * Style section: dedicated controls for the form's buttons — the submit
	 * button and the multi-page wizard's Back/Next buttons. When set they
	 * override the theme's button styling; when empty no CSS is emitted and
	 * the buttons keep the theme's own styling.
	 */
	private function register_button_style_controls(): void {
		$buttons = '{{WRAPPER}} ' . FieldSelectors::BUTTONS;

		$this->start_controls_section(
			'relevant_form_button_style',
			[
				'label' => esc_html__( 'Button styles', 'relevant-forms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'button_bg_color',
			[
				'label'     => esc_html__( 'Background', 'relevant-forms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					$buttons => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'button_text_color',
			[
				'label'     => esc_html__( 'Text color', 'relevant-forms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					$buttons => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'button_radius',
			[
				'label'      => esc_html__( 'Rounded corners', 'relevant-forms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					$buttons => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'button_padding',
			[
				'label'      => esc_html__( 'Padding', 'relevant-forms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', '%' ],
				'selectors'  => [
					$buttons => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
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
