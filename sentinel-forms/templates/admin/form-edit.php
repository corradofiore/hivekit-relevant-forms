<?php
/**
 * Form editor template.
 *
 * @package SentinelForms
 *
 * @var SentinelForms\Forms\Form                     $form      Form being edited.
 * @var array<string, SentinelForms\Forms\Fields\FieldType> $types Available field types.
 * @var bool                                         $isEditing Whether editing an existing form.
 * @var array<int, array{label: string, items: array<int, array{id: int, title: string}>}> $redirectTargets Published pages/posts for the plain picker.
 * @var bool                                         $redirectSearch   Whether to use the search selector instead of a drop-down.
 * @var array{id: int, title: string}|null           $redirectSelected Current anchor shown in the search selector.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.
?>
<div class="wrap sentinel-forms-builder">
	<h1><?php $isEditing ? esc_html_e( 'Edit Form', 'sentinel-forms' ) : esc_html_e( 'Add New Form', 'sentinel-forms' ); ?></h1>

	<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Form saved.', 'sentinel-forms' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="sentinel_forms_save_form">
		<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form->id ); ?>">
		<?php wp_nonce_field( 'sentinel_form_save' ); ?>

		<nav class="nav-tab-wrapper sentinel-forms-tabs" aria-label="<?php esc_attr_e( 'Form editor sections', 'sentinel-forms' ); ?>">
			<a class="nav-tab nav-tab-active sentinel-forms-tabs__tab" href="<?php echo esc_url( '#sf-panel-settings' ); ?>"><?php esc_html_e( 'Form settings', 'sentinel-forms' ); ?></a>
			<a class="nav-tab sentinel-forms-tabs__tab" href="<?php echo esc_url( '#sf-panel-fields' ); ?>"><?php esc_html_e( 'Form fields', 'sentinel-forms' ); ?></a>
		</nav>

		<div id="sf-panel-settings" class="sentinel-forms-tabs__panel">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sentinel-form-name"><?php esc_html_e( 'Form name', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="sentinel-form-name" name="form_name" value="<?php echo esc_attr( $form->name ); ?>" required>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-form-status"><?php esc_html_e( 'Status', 'sentinel-forms' ); ?></label></th>
				<td>
					<select id="sentinel-form-status" name="form_status">
						<option value="draft" <?php selected( $form->status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'sentinel-forms' ); ?></option>
						<option value="published" <?php selected( $form->status, 'published' ); ?>><?php esc_html_e( 'Published', 'sentinel-forms' ); ?></option>
						<option value="archived" <?php selected( $form->status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'sentinel-forms' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Only published forms can be rendered on the front end.', 'sentinel-forms' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Confirmation', 'sentinel-forms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sentinel-confirmation-message"><?php esc_html_e( 'Message', 'sentinel-forms' ); ?></label></th>
				<td>
					<textarea class="large-text" id="sentinel-confirmation-message" name="confirmation_message" rows="2"><?php echo esc_textarea( $form->schema->confirmationMessage() ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Shown after a successful submission when no redirect is set.', 'sentinel-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-redirect-url"><?php esc_html_e( 'Redirect URL', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="sentinel-redirect-url" name="redirect_url" value="<?php echo esc_attr( $form->schema->redirectUrl() ); ?>" <?php disabled( $form->schema->redirectPageId() > 0 && ( $redirectSearch || [] !== $redirectTargets ) ); ?>>
					<p class="description"><?php esc_html_e( 'Where to send visitors after a successful submission. Disabled while a page or post is selected.', 'sentinel-forms' ); ?></p>
				</td>
			</tr>
			<?php if ( $redirectSearch ) : ?>
				<tr>
					<th scope="row"><label for="sf-redirect-search"><?php esc_html_e( 'Page or post', 'sentinel-forms' ); ?></label></th>
					<td>
						<input type="search" id="sf-redirect-search" class="regular-text" value="<?php echo esc_attr( null !== $redirectSelected ? $redirectSelected['title'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Search pages and posts…', 'sentinel-forms' ); ?>" autocomplete="off">
						<select id="sf-redirect-page" name="redirect_page">
							<option value=""><?php esc_html_e( 'Select a page or post…', 'sentinel-forms' ); ?></option>
							<?php if ( null !== $redirectSelected ) : ?>
								<option value="<?php echo esc_attr( (string) $redirectSelected['id'] ); ?>" selected><?php echo esc_html( $redirectSelected['title'] ); ?></option>
							<?php endif; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Start typing to search. Redirects to the selected item even if its URL changes.', 'sentinel-forms' ); ?></p>
						<noscript><p class="description"><?php esc_html_e( 'Enable JavaScript to search pages and posts.', 'sentinel-forms' ); ?></p></noscript>
					</td>
				</tr>
			<?php elseif ( [] !== $redirectTargets ) : ?>
				<tr>
					<th scope="row"><label for="sf-redirect-page"><?php esc_html_e( 'Page or post', 'sentinel-forms' ); ?></label></th>
					<td>
						<select id="sf-redirect-page" name="redirect_page">
							<option value=""><?php esc_html_e( 'Select a page or post…', 'sentinel-forms' ); ?></option>
							<?php foreach ( $redirectTargets as $group ) : ?>
								<optgroup label="<?php echo esc_attr( $group['label'] ); ?>">
									<?php foreach ( $group['items'] as $item ) : ?>
										<option value="<?php echo esc_attr( (string) $item['id'] ); ?>" <?php selected( $form->schema->redirectPageId(), $item['id'] ); ?>><?php echo esc_html( $item['title'] ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Redirect to this item; it keeps working even if its URL changes.', 'sentinel-forms' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<h2><?php esc_html_e( 'Notifications', 'sentinel-forms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sentinel-notification-emails"><?php esc_html_e( 'Email recipients', 'sentinel-forms' ); ?></label></th>
				<td>
					<textarea class="large-text" id="sentinel-notification-emails" name="notification_emails" rows="3" placeholder="<?php esc_attr_e( 'one address per line', 'sentinel-forms' ); ?>"><?php echo esc_textarea( implode( "\n", $form->schema->notificationEmails() ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Leave empty to use the site administrator email.', 'sentinel-forms' ); ?></p>
				</td>
			</tr>
		</table>

		<?php $ai_policy = $form->schema->aiPolicy(); ?>
		<h2><?php esc_html_e( 'AI classification', 'sentinel-forms' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Requires Sentinel Cloud with classification enabled in Sentinel Forms → Settings. Submissions are redacted before they are sent.', 'sentinel-forms' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Classify submissions', 'sentinel-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="ai_enabled" value="1" <?php checked( false !== ( $ai_policy['enabled'] ?? true ) ); ?>>
						<?php esc_html_e( 'Classify new submissions to this form with Sentinel Cloud', 'sentinel-forms' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-ai-purpose"><?php esc_html_e( 'Form purpose', 'sentinel-forms' ); ?></label></th>
				<td>
					<textarea class="large-text" id="sentinel-ai-purpose" name="ai_purpose" rows="2"><?php echo esc_textarea( (string) ( $ai_policy['purpose'] ?? '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'What a relevant submission looks like, e.g. "Requests for quotes from homeowners".', 'sentinel-forms' ); ?></p>
				</td>
			</tr>
		</table>
		</div><!-- #sf-panel-settings -->

		<div id="sf-panel-fields" class="sentinel-forms-tabs__panel">

		<h2><?php esc_html_e( 'Fields', 'sentinel-forms' ); ?></h2>

		<div id="sf-fields">
			<?php
			foreach ( $form->schema->fields() as $definition ) {
				$field_type = SentinelForms\Forms\Fields\FieldTypeRegistry::get( (string) $definition['type'] );
				if ( null === $field_type ) {
					continue;
				}
				require SENTINEL_FORMS_PATH . 'templates/admin/field-row.php';
			}
			?>
		</div>

		<p>
			<select id="sf-add-field">
				<option value=""><?php esc_html_e( 'Add a field…', 'sentinel-forms' ); ?></option>
				<?php foreach ( $types as $field_type ) : ?>
					<option value="<?php echo esc_attr( $field_type->type() ); ?>"><?php echo esc_html( $field_type->label() ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		</div><!-- #sf-panel-fields -->

		<?php submit_button( $isEditing ? __( 'Save Form', 'sentinel-forms' ) : __( 'Create Form', 'sentinel-forms' ) ); ?>
	</form>

	<?php foreach ( $types as $field_type ) : ?>
		<template id="sf-field-template-<?php echo esc_attr( $field_type->type() ); ?>">
			<?php
			$definition = [
				'id'            => '{ID}',
				'type'          => $field_type->type(),
				'label'         => '',
				'required'      => false,
				'placeholder'   => '',
				'help_text'     => '',
				'default_value' => '',
				'choices'       => [],
			];
			require SENTINEL_FORMS_PATH . 'templates/admin/field-row.php';
			?>
		</template>
	<?php endforeach; ?>
</div>
