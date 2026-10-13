<?php
/**
 * Beaver Builder module registration: "Relevant Form".
 *
 * Uses Beaver Builder's declarative FLBuilderModuleType API (no PHP class
 * file needed): a settings form plus a render callback that delegates to the
 * same code path as the shortcode. Loaded by BeaverIntegration::load() while
 * Beaver Builder is active.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Integrations\BeaverBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Defence in depth: the autoloader may reach this file on sites without Beaver Builder.
if ( ! class_exists( '\FLBuilderModuleType' ) ) {
	return;
}

\FLBuilderModuleType::register(
	'relevant-form',
	[
		'name'        => esc_html__( 'Relevant Form', 'relevant-forms' ),
		'description' => esc_html__( 'Embed a form built with Relevant Forms.', 'relevant-forms' ),
		'category'    => esc_html__( 'Relevant Forms', 'relevant-forms' ),
		'form'        => [
			'form'  => [
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
			'style' => ModuleStyles::styleTab(),
		],
		'render'      => static function ( $settings ): string {
			return ModuleStyles::render( $settings );
		},
	]
);
