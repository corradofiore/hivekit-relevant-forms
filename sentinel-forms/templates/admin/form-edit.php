<?php
/**
 * Form editor template.
 *
 * @package RelevantForms
 *
 * @var RelevantForms\Forms\Form                     $form      Form being edited.
 * @var array<string, RelevantForms\Forms\Fields\FieldType> $types Available field types.
 * @var bool                                         $isEditing Whether editing an existing form.
 * @var array<int, array{label: string, items: array<int, array{id: int, title: string}>}> $redirectTargets Published pages/posts for the plain picker.
 * @var bool                                         $redirectSearch   Whether to use the search selector instead of a drop-down.
 * @var array{id: int, title: string}|null           $redirectSelected Current anchor shown in the search selector.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.

$pageTitle = __( 'Add New Form', 'relevant-forms' );

if ( $isEditing ) {
	$pageTitle = '' !== trim( $form->name )
		? sprintf(
			/* translators: %s: form name. */
			__( 'Edit Form: %s', 'relevant-forms' ),
			$form->name
		)
		: __( 'Edit Form', 'relevant-forms' );
}
?>
<div class="wrap relevant-forms-builder">
	<h1><?php echo esc_html( $pageTitle ); ?></h1>

	<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Form saved.', 'relevant-forms' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="relevant_forms_save_form">
		<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form->id ); ?>">
		<?php wp_nonce_field( 'relevant_form_save' ); ?>

		<nav class="nav-tab-wrapper relevant-forms-tabs" aria-label="<?php esc_attr_e( 'Form editor sections', 'relevant-forms' ); ?>">
			<a class="nav-tab nav-tab-active relevant-forms-tabs__tab" href="<?php echo esc_url( '#rf-panel-settings' ); ?>"><?php esc_html_e( 'Form settings', 'relevant-forms' ); ?></a>
			<a class="nav-tab relevant-forms-tabs__tab" href="<?php echo esc_url( '#rf-panel-fields' ); ?>"><?php esc_html_e( 'Form fields', 'relevant-forms' ); ?></a>
		</nav>

		<div id="rf-panel-settings" class="relevant-forms-tabs__panel">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="relevant-form-name"><?php esc_html_e( 'Form name', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="relevant-form-name" name="form_name" value="<?php echo esc_attr( $form->name ); ?>" required>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-form-status"><?php esc_html_e( 'Status', 'relevant-forms' ); ?></label></th>
				<td>
					<select id="relevant-form-status" name="form_status">
						<option value="draft" <?php selected( $form->status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'relevant-forms' ); ?></option>
						<option value="published" <?php selected( $form->status, 'published' ); ?>><?php esc_html_e( 'Published', 'relevant-forms' ); ?></option>
						<option value="archived" <?php selected( $form->status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'relevant-forms' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Only published forms can be rendered on the front end.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
		</table>

		<?php $confirmation_mode = $form->schema->confirmationMode(); ?>
		<?php $redirect_picker_available = $redirectSearch || [] !== $redirectTargets || $form->schema->redirectPageId() > 0; ?>
		<h2><?php esc_html_e( 'Confirmation', 'relevant-forms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="relevant-confirmation-mode"><?php esc_html_e( 'After a successful submission', 'relevant-forms' ); ?></label></th>
				<td>
					<select id="relevant-confirmation-mode" name="confirmation_mode">
						<option value="message" <?php selected( $confirmation_mode, 'message' ); ?>><?php esc_html_e( 'Show a confirmation message', 'relevant-forms' ); ?></option>
						<option value="url" <?php selected( $confirmation_mode, 'url' ); ?>><?php esc_html_e( 'Redirect to a URL', 'relevant-forms' ); ?></option>
						<?php if ( $redirect_picker_available ) : ?>
							<option value="page" <?php selected( $confirmation_mode, 'page' ); ?>><?php esc_html_e( 'Redirect to a page or post', 'relevant-forms' ); ?></option>
						<?php endif; ?>
					</select>
				</td>
			</tr>
			<tr id="rf-confirmation-message-row">
				<th scope="row"><label for="relevant-confirmation-message"><?php esc_html_e( 'Message', 'relevant-forms' ); ?></label></th>
				<td>
					<textarea class="relevant-forms-textarea" id="relevant-confirmation-message" name="confirmation_message" rows="4"><?php echo esc_textarea( $form->schema->confirmationMessage() ); ?></textarea>
					<p class="description relevant-forms-textarea-caption"><?php esc_html_e( 'Shown on the page after a successful submission.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr id="rf-confirmation-url-row">
				<th scope="row"><label for="relevant-redirect-url"><?php esc_html_e( 'Redirect URL', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="relevant-redirect-url" name="redirect_url" value="<?php echo esc_attr( $form->schema->redirectUrl() ); ?>" placeholder="https://...">
					<p class="description"><?php esc_html_e( 'Where to send visitors after a successful submission.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<?php if ( $redirect_picker_available ) : ?>
				<tr id="rf-confirmation-page-row">
					<th scope="row"><label for="<?php echo esc_attr( $redirectSearch ? 'rf-redirect-search' : 'rf-redirect-page' ); ?>"><?php esc_html_e( 'Page or post', 'relevant-forms' ); ?></label></th>
					<td>
						<?php if ( $redirectSearch ) : ?>
							<input type="search" id="rf-redirect-search" class="regular-text" value="<?php echo esc_attr( null !== $redirectSelected ? $redirectSelected['title'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Search pages and posts…', 'relevant-forms' ); ?>" autocomplete="off">
							<select id="rf-redirect-page" name="redirect_page">
								<option value=""><?php esc_html_e( 'Select a page or post…', 'relevant-forms' ); ?></option>
								<?php if ( null !== $redirectSelected ) : ?>
									<option value="<?php echo esc_attr( (string) $redirectSelected['id'] ); ?>" selected><?php echo esc_html( $redirectSelected['title'] ); ?></option>
								<?php endif; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Start typing to search. Redirects to the selected item even if its URL changes.', 'relevant-forms' ); ?></p>
							<noscript><p class="description"><?php esc_html_e( 'Enable JavaScript to search pages and posts.', 'relevant-forms' ); ?></p></noscript>
						<?php else : ?>
							<select id="rf-redirect-page" name="redirect_page">
								<option value=""><?php esc_html_e( 'Select a page or post…', 'relevant-forms' ); ?></option>
								<?php foreach ( $redirectTargets as $group ) : ?>
									<optgroup label="<?php echo esc_attr( $group['label'] ); ?>">
										<?php foreach ( $group['items'] as $item ) : ?>
											<option value="<?php echo esc_attr( (string) $item['id'] ); ?>" <?php selected( $form->schema->redirectPageId(), $item['id'] ); ?>><?php echo esc_html( $item['title'] ); ?></option>
										<?php endforeach; ?>
									</optgroup>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Redirect to this item; it keeps working even if its URL changes.', 'relevant-forms' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<h2><?php esc_html_e( 'Notifications', 'relevant-forms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="relevant-notification-emails"><?php esc_html_e( 'Email recipients', 'relevant-forms' ); ?></label></th>
				<td>
					<textarea class="relevant-forms-textarea" id="relevant-notification-emails" name="notification_emails" rows="6" placeholder="<?php esc_attr_e( 'one address per line', 'relevant-forms' ); ?>"><?php echo esc_textarea( implode( "\n", $form->schema->notificationEmails() ) ); ?></textarea>
					<p class="description relevant-forms-textarea-caption"><?php esc_html_e( 'Leave empty to use the site administrator email.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
		</table>

		<?php
		$ai_policy    = $form->schema->aiPolicy();
		$sms_alert_on = ! empty( $ai_policy['sms_alert'] );
		$sms_phone    = (string) ( $ai_policy['sms_phone'] ?? '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice state carried in the redirect.
		$sms_error = isset( $_GET['sms_error'] ) ? sanitize_key( wp_unslash( (string) $_GET['sms_error'] ) ) : '';

		$sms_message      = '';
		$sms_message_tone = '';

		if ( 'unverified_phone' === $sms_error ) {
			// The save was refused for this number: the error belongs below the field.
			$sms_message      = __( 'The phone number was not saved: verify it with the 6-digit code first, then save again.', 'relevant-forms' );
			$sms_message_tone = 'error';
		} elseif ( '' !== $sms_phone ) {
			$sms_message      = __( 'Verified', 'relevant-forms' );
			$sms_message_tone = 'ok';
		}
		?>
		<div class="relevant-forms-ai-panel">
			<h2><?php esc_html_e( 'AI classification', 'relevant-forms' ); ?></h2>
			<p class="relevant-forms-ai-panel__intro"><?php esc_html_e( 'AI evaluates meaning, relevance, intent and urgency. Important submissions receive a transparent attention score, so they do not look identical to every other email.', 'relevant-forms' ); ?></p>
			<p class="description"><?php esc_html_e( 'Requires Relevant Cloud with classification enabled in Relevant Forms → Settings. Submissions are redacted before they are sent.', 'relevant-forms' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Classify submissions', 'relevant-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="ai_enabled" value="1" <?php checked( false !== ( $ai_policy['enabled'] ?? true ) ); ?>>
						<?php esc_html_e( 'Classify new submissions to this form with Relevant Cloud', 'relevant-forms' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-ai-purpose"><?php esc_html_e( 'Form purpose', 'relevant-forms' ); ?></label></th>
				<td>
					<textarea class="relevant-forms-textarea" id="relevant-ai-purpose" name="ai_purpose" rows="4"><?php echo esc_textarea( (string) ( $ai_policy['purpose'] ?? '' ) ); ?></textarea>
					<p class="description relevant-forms-textarea-caption"><?php esc_html_e( 'Tell our Relevant AI what a relevant submission looks like, e.g. "Requests for quotes from homeowners", "RSVP for training events", etc.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-ai-spam"><?php esc_html_e( 'What counts as spam', 'relevant-forms' ); ?></label></th>
				<td>
					<textarea class="relevant-forms-textarea" id="relevant-ai-spam" name="ai_spam" rows="4"><?php echo esc_textarea( (string) ( $ai_policy['spam'] ?? '' ) ); ?></textarea>
					<p class="description relevant-forms-textarea-caption"><?php esc_html_e( 'Describe messages this form should treat as spam, e.g. "Automated sales pitches and link drops", "Comments not relevant to (insert your site\'s topic) or very generic". One category per line.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Priority SMS', 'relevant-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="relevant-ai-sms-alert" name="ai_sms_alert" value="1" <?php checked( $sms_alert_on ); ?>>
						<?php esc_html_e( 'Receive an SMS when a submission is particularly relevant (e.g. a request for a quote).', 'relevant-forms' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-ai-sms-phone"><?php esc_html_e( 'Priority SMS recipient', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="tel" class="regular-text" id="relevant-ai-sms-phone" name="ai_sms_phone" value="<?php echo esc_attr( $sms_phone ); ?>" data-stored="<?php echo esc_attr( $sms_phone ); ?>" placeholder="+39 333 1234567" autocomplete="tel" <?php disabled( ! $sms_alert_on ); ?>>
					<button type="button" class="button" id="rf-sms-send-code" <?php disabled( ! $sms_alert_on ); ?>><?php esc_html_e( 'Send code', 'relevant-forms' ); ?></button>
					<p class="rf-sms-message<?php echo '' !== $sms_message_tone ? ' rf-sms-message--' . esc_attr( $sms_message_tone ) : ''; ?>" id="rf-sms-phone-message" role="status" aria-live="polite" data-error="<?php echo 'error' === $sms_message_tone ? '1' : '0'; ?>"<?php echo '' === $sms_message ? ' hidden' : ''; ?>><?php echo esc_html( $sms_message ); ?></p>
					<div id="rf-sms-code-row" hidden>
						<input type="text" class="small-text" id="rf-sms-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="<?php esc_attr_e( '6-digit code', 'relevant-forms' ); ?>">
						<button type="button" class="button" id="rf-sms-verify-code"><?php esc_html_e( 'Verify code', 'relevant-forms' ); ?></button>
						<p class="rf-sms-message" id="rf-sms-code-message" role="status" aria-live="polite" hidden></p>
					</div>
					<p class="description"><?php esc_html_e( 'We text a 6-digit code to this number. A new or changed number is only saved after the code is verified.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
		</table>
		</div><!-- /.relevant-forms-ai-panel -->
		</div><!-- #rf-panel-settings -->

		<div id="rf-panel-fields" class="relevant-forms-tabs__panel">

		<?php $builder_fields = $form->schema->fields(); ?>
		<div class="rf-fields-columns">
			<div class="rf-fields-column rf-fields-column--list">
				<p class="rf-fields-add">
					<select id="rf-add-field">
						<option value=""><?php esc_html_e( 'Add a field…', 'relevant-forms' ); ?></option>
						<?php foreach ( $types as $field_type ) : ?>
							<option value="<?php echo esc_attr( $field_type->type() ); ?>"><?php echo esc_html( $field_type->label() ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<div id="rf-fields-list" class="rf-fields-list" role="listbox" aria-label="<?php esc_attr_e( 'Form fields', 'relevant-forms' ); ?>">
					<?php
					$builder_index = 0;
					foreach ( $builder_fields as $definition ) {
						$field_type = RelevantForms\Forms\Fields\FieldTypeRegistry::get( (string) $definition['type'] );
						if ( null === $field_type ) {
							continue;
						}
						$is_first = 0 === $builder_index;
						require RELEVANT_FORMS_PATH . 'templates/admin/field-list-item.php';
						++$builder_index;
					}
					?>
				</div>
			</div>
			<div class="rf-fields-column rf-fields-column--editor">
				<h2 id="rf-fields-title"><?php esc_html_e( 'Fields', 'relevant-forms' ); ?></h2>
				<p id="rf-fields-empty" class="rf-fields-empty"<?php echo [] === $builder_fields ? '' : ' hidden'; ?>><?php esc_html_e( 'Select a field to edit it, or add a new one.', 'relevant-forms' ); ?></p>
				<div id="rf-fields-editors">
					<?php
					foreach ( $builder_fields as $definition ) {
						$field_type = RelevantForms\Forms\Fields\FieldTypeRegistry::get( (string) $definition['type'] );
						if ( null === $field_type ) {
							continue;
						}
						require RELEVANT_FORMS_PATH . 'templates/admin/field-row.php';
					}
					?>
				</div>

				<h2 id="rf-fields-preview-title"<?php echo [] === $builder_fields ? ' hidden' : ''; ?>><?php esc_html_e( 'Preview', 'relevant-forms' ); ?></h2>
				<div id="rf-fields-preview" class="rf-fields-preview"<?php echo [] === $builder_fields ? ' hidden' : ''; ?>>
					<?php
					foreach ( $builder_fields as $definition ) {
						$field_type = RelevantForms\Forms\Fields\FieldTypeRegistry::get( (string) $definition['type'] );
						if ( null === $field_type ) {
							continue;
						}
						require RELEVANT_FORMS_PATH . 'templates/admin/field-preview.php';
					}
					?>
				</div>

				<h2 id="rf-fields-visibility-title"<?php echo [] === $builder_fields ? ' hidden' : ''; ?>><?php esc_html_e( 'Field visibility rules', 'relevant-forms' ); ?></h2>
				<div id="rf-fields-visibility" class="rf-fields-visibility"<?php echo [] === $builder_fields ? ' hidden' : ''; ?>>
					<?php
					foreach ( $builder_fields as $builder_visibility_index => $definition ) {
						$field_type = RelevantForms\Forms\Fields\FieldTypeRegistry::get( (string) $definition['type'] );
						if ( null === $field_type ) {
							continue;
						}
						require RELEVANT_FORMS_PATH . 'templates/admin/field-visibility.php';
					}
					?>
				</div>
			</div>
		</div>
		</div><!-- #rf-panel-fields -->

		<?php submit_button( $isEditing ? __( 'Save Form', 'relevant-forms' ) : __( 'Create Form', 'relevant-forms' ) ); ?>
	</form>

	<?php foreach ( $types as $field_type ) : ?>
		<template id="rf-field-template-<?php echo esc_attr( $field_type->type() ); ?>">
			<?php
			$definition = [
				'id'            => '{ID}',
				'type'          => $field_type->type(),
				'label'         => 'section' === $field_type->type() ? __( 'A New Section', 'relevant-forms' ) : '',
				'required'      => false,
				'placeholder'   => '',
				'help_text'     => '',
				'default_value' => '',
				'choices'       => [],
			];

			if ( 'text' === $field_type->type() ) {
				$definition['max_length'] = RelevantForms\Forms\Fields\TextField::MAX_LENGTH;
			} elseif ( 'textarea' === $field_type->type() ) {
				$definition['max_length'] = RelevantForms\Forms\Fields\TextareaField::MAX_LENGTH;
			}

			$is_first = false;
			require RELEVANT_FORMS_PATH . 'templates/admin/field-list-item.php';
			require RELEVANT_FORMS_PATH . 'templates/admin/field-row.php';
			require RELEVANT_FORMS_PATH . 'templates/admin/field-preview.php';

			// Template fragments have no previous fields: admin.js fills the
			// source options when the field is added to the list.
			$builder_visibility_index = 0;
			require RELEVANT_FORMS_PATH . 'templates/admin/field-visibility.php';
			?>
		</template>
	<?php endforeach; ?>

	<template id="rf-visibility-group-template">
		<div class="rf-field-visibility__group" data-group>
			<p class="rf-field-visibility__join" hidden><?php esc_html_e( 'or', 'relevant-forms' ); ?></p>
			<div class="rf-field-visibility__rules" data-rules></div>
			<div class="rf-field-visibility__group-actions">
				<button type="button" class="button-link rf-visibility-add-rule"><?php esc_html_e( '+ Add rule', 'relevant-forms' ); ?></button>
				<button type="button" class="button-link rf-visibility-remove-group"><?php esc_html_e( 'Remove group', 'relevant-forms' ); ?></button>
			</div>
		</div>
	</template>

	<template id="rf-visibility-rule-template">
		<div class="rf-field-visibility__rule" data-rule>
			<select class="rf-visibility-source" name="relevant_fields[{FIELDID}][visibility][groups][{GROUP}][{RULE}][field]"></select>
			<select class="rf-visibility-operator" name="relevant_fields[{FIELDID}][visibility][groups][{GROUP}][{RULE}][operator]"></select>
			<span class="rf-field-visibility__value" data-value></span>
			<button type="button" class="button-link rf-visibility-remove"><?php esc_html_e( 'Remove', 'relevant-forms' ); ?></button>
		</div>
	</template>
</div>
