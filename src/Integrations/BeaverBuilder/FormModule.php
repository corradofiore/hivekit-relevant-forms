<?php
/**
 * Beaver Builder module: "Relevant Form".
 *
 * Loaded by BeaverIntegration::load() while Beaver Builder is active, so the
 * FLBuilderModule parent only needs to exist at that point.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\BeaverBuilder;

// Defence in depth: the autoloader may reach this file on sites without Beaver Builder.
if ( ! class_exists( '\FLBuilderModule' ) ) {
	return;
}

/**
 * Module definition: a form picker plus server-side rendering through the
 * same code path as the shortcode. The registration call at the bottom of
 * this file adds it to the builder.
 */
final class FormModule extends \FLBuilderModule {

	/**
	 * Clean slug regardless of how the parent derives it from the class name.
	 *
	 * @var string
	 */
	public $slug = 'relevant-form';

	/**
	 * Module registration.
	 */
	public function __construct() {
		parent::__construct(
			[
				'name'        => esc_html__( 'Relevant Form', 'relevant-forms' ),
				'description' => esc_html__( 'Embed a form built with Relevant Forms.', 'relevant-forms' ),
				'category'    => esc_html__( 'Relevant Forms', 'relevant-forms' ),
				'dir'         => __DIR__ . '/',
				'url'         => RELEVANT_FORMS_URL . 'src/Integrations/BeaverBuilder/',
			]
		);

		// The parent may derive a slug from the (namespaced) class name.
		$this->slug = 'relevant-form';
	}

	/**
	 * Front-end output: identical to the [relevant_form] shortcode.
	 *
	 * @param object $settings Module settings.
	 * @param string $content  Module content (unused).
	 */
	public function render( $settings, $content = '' ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Complete, already-escaped form markup from FormRenderer.
		echo BeaverIntegration::renderForm( (int) ( $settings->form_id ?? 0 ) );
	}
}

// Register the module and its settings form with Beaver Builder.
\FLBuilder::register_module(
	FormModule::class,
	[
		'form' => [
			'title'    => esc_html__( 'Form', 'relevant-forms' ),
			'sections' => [
				'form' => [
					'title'  => '',
					'fields' => [
						'form_id' => [
							'type'    => 'select',
							'label'   => esc_html__( 'Form', 'relevant-forms' ),
							'options' => BeaverIntegration::formChoices(),
							'default' => '',
							'help'    => esc_html__( 'Only published forms are rendered on the front end.', 'relevant-forms' ),
						],
					],
				],
			],
		],
	]
);
