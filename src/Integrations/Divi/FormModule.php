<?php
/**
 * Divi Builder module: "Relevant Form" in the Insert Module dialog.
 *
 * Loaded by DiviIntegration::loadModule() (hooked on `et_builder_ready`), so
 * the ET_Builder_Module parent only needs to exist at that point — never when
 * this file is read outside an active Divi Builder.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\Divi;

// Defence in depth: the autoloader may reach this file on sites without Divi.
if ( ! class_exists( '\ET_Builder_Module' ) ) {
	return;
}

/**
 * Module definition: a form picker plus server-side rendering through the
 * same code path as the shortcode.
 */
final class FormModule extends \ET_Builder_Module {

	/**
	 * Unique module slug (mirrored by the Visual Builder component).
	 *
	 * @var string
	 */
	public $slug = 'relevant_form';

	/**
	 * Visual Builder support.
	 *
	 * @var string
	 */
	public $vb_support = 'on';

	/**
	 * Module icon shown in the module picker.
	 *
	 * Declared because the builder framework reads it as a public property
	 * but does not define it (PHP 8.2+ would emit a dynamic-property
	 * deprecation for the assignment in init()).
	 *
	 * @var string
	 */
	public $icon = '';

	/**
	 * Module credits (info tooltip in the settings panel).
	 *
	 * @var array<string, string>
	 */
	protected $module_credits = [
		'module_uri' => 'https://relevantforms.com/',
		'author'     => 'Relevant Forms',
		'author_uri' => 'https://relevantforms.com/',
	];

	/**
	 * Display name and icon shown in the module picker.
	 */
	public function init(): void {
		$this->name             = esc_html__( 'Relevant Form', 'relevant-forms' );
		$this->icon             = 'R';
		$this->main_css_element = '%%order_class%%';
	}

	/**
	 * Module settings: pick the form to embed.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return [
			'form_id' => [
				'label'           => esc_html__( 'Form', 'relevant-forms' ),
				'type'            => 'select',
				'options'         => DiviIntegration::formChoices(),
				'option_category' => 'basic_option',
				'description'     => esc_html__( 'Only published forms are rendered on the front end.', 'relevant-forms' ),
				'toggle_slug'     => 'main_content',
			],
		];
	}

	/**
	 * Front-end output: identical to the [relevant_form] shortcode.
	 *
	 * @param array<string, mixed> $attrs       Unprocessed attributes.
	 * @param string|null          $content     Module inner content.
	 * @param string               $render_slug Module slug.
	 */
	public function render( $attrs, $content = null, $render_slug = '' ): string {
		return DiviIntegration::renderForm( (int) ( $this->props['form_id'] ?? 0 ) );
	}
}

new FormModule();
