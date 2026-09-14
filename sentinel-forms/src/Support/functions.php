<?php
/**
 * Public template functions.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use SentinelForms\Forms\FormRenderer;

if ( ! function_exists( 'sentinel_forms_render' ) ) {
	/**
	 * Render a published form by ID (doc §9).
	 *
	 * @param int $formId The form ID.
	 */
	function sentinel_forms_render( int $formId ): string {
		if ( ! class_exists( FormRenderer::class ) ) {
			return '';
		}

		return FormRenderer::render( $formId );
	}
}

if ( ! function_exists( 'sentinel_forms_form_exists' ) ) {
	/**
	 * Whether a published form with the given ID exists.
	 *
	 * @param int $formId The form ID.
	 */
	function sentinel_forms_form_exists( int $formId ): bool {
		if ( ! class_exists( \SentinelForms\Forms\FormRepository::class ) ) {
			return false;
		}

		return ( new \SentinelForms\Forms\FormRepository() )->findPublished( $formId ) !== null;
	}
}
