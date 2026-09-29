<?php
/**
 * Public template functions.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use RelevantForms\Forms\FormRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'relevant_forms_render' ) ) {
	/**
	 * Render a published form by ID (doc §9).
	 *
	 * @param int $formId The form ID.
	 */
	function relevant_forms_render( int $formId ): string {
		if ( ! class_exists( FormRenderer::class ) ) {
			return '';
		}

		return FormRenderer::render( $formId );
	}
}
