<?php
/**
 * Form preview markup for the form editor.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Preview;

use RelevantForms\Forms\Form;

/**
 * Renders the Form preview tab's body: the front-end form in preview mode
 * plus the confirmation the editor reveals when the preview is submitted.
 *
 * One source of truth for the initial page render and the admin-ajax refresh
 * (FormPreviewController), so a refreshed preview can never drift from the
 * markup the editor started with.
 */
final class FormPreview {

	/**
	 * Render the preview body for one form.
	 *
	 * @param Form $form Form to preview (any status: drafts preview too).
	 */
	public static function body( Form $form ): string {
		// The variable the template works with, named here so the include's
		// contract is visible from the calling class.
		$previewForm = $form;

		ob_start();
		require RELEVANT_FORMS_PATH . 'templates/admin/form-preview-body.php';

		return (string) ob_get_clean();
	}
}
