<?php
/**
 * Form preview body (Form preview tab of the form editor).
 *
 * The markup is the real front-end render in preview mode: the controls are
 * the ones a visitor gets, but nothing is wired for storage — no upload
 * tokens and no Save and Resume — and admin.js shows the confirmation
 * instead of posting. The body is replaced in place through admin-ajax
 * after a save so the preview always matches the stored form.
 *
 * @package RelevantForms
 *
 * @var RelevantForms\Forms\Form $previewForm Form being edited.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.

if ( $previewForm->id <= 0 ) :
	?>
	<p class="description"><?php esc_html_e( 'Save the form once to see the preview.', 'relevant-forms' ); ?></p>
	<?php
else :
	$preview_has_file_field = false;

	foreach ( $previewForm->schema->fields() as $preview_definition ) {
		if ( 'file' === (string) ( $preview_definition['type'] ?? '' ) ) {
			$preview_has_file_field = true;
			break;
		}
	}

	$preview_mode = $previewForm->schema->confirmationMode();

	if ( 'message' === $preview_mode ) {
		$preview_message = trim( $previewForm->schema->confirmationMessage() );

		if ( '' === $preview_message ) {
			$preview_message = __( 'Thank you for your message. We will be in touch soon.', 'relevant-forms' );
		}

		$preview_confirmation = sprintf( '<p class="relevant-forms-success" role="status">%s</p>', esc_html( $preview_message ) );
	} else {
		$preview_target = 'url' === $preview_mode
			? $previewForm->schema->redirectUrl()
			: (string) get_the_title( $previewForm->schema->redirectPageId() );

		if ( '' === $preview_target ) {
			$preview_target = 'url' === $preview_mode
				? __( 'the configured URL', 'relevant-forms' )
				: __( 'the linked page', 'relevant-forms' );
		}

		$preview_confirmation = sprintf(
			'<p class="relevant-forms-preview__redirect" role="status">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: the redirect target (URL or page title). */
					__( 'After a successful submission this form sends visitors to %s. The preview stays on this page.', 'relevant-forms' ),
					$preview_target
				)
			)
		);
	}
	?>
	<div class="relevant-forms-preview__success" data-rf-form-preview-success hidden>
		<?php echo $preview_confirmation; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
		<p class="description relevant-forms-preview__note"><?php esc_html_e( 'Preview only — nothing was submitted, no entry was stored and no notification was sent.', 'relevant-forms' ); ?></p>
	</div>
	<?php if ( $preview_has_file_field ) : ?>
		<p class="description relevant-forms-preview__hint"><?php esc_html_e( 'File uploads stay disabled in the preview: files chosen here are never uploaded.', 'relevant-forms' ); ?></p>
	<?php endif; ?>
	<div class="relevant-forms-preview__form">
		<?php echo \RelevantForms\Forms\FormRenderer::renderForm( $previewForm, null, null, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup escaped by the field renderers. ?>
	</div>
	<?php
endif;
