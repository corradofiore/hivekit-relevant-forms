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

$rf_save_label = $isEditing ? __( 'Save Form', 'relevant-forms' ) : __( 'Create Form', 'relevant-forms' );
?>
<div class="wrap relevant-forms-builder">
	<h1><?php echo esc_html( $pageTitle ); ?></h1>

	<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Form saved.', 'relevant-forms' ); ?></p>
		</div>
	<?php endif; ?>

	<?php /* In-flight and error feedback for the AJAX save; a successful save reveals a native notice injected by initFormSave(). */ ?>
	<p class="rf-sms-message" id="rf-form-save-message" role="status" aria-live="polite" hidden></p>

	<?php
	$rf_has_payment_fields = \RelevantForms\Payments\PaymentTotals::hasPaymentFields( $form->schema->fields() );
	$rf_payments_ready     = ( new \RelevantForms\Payments\CloudPaymentsGateway() )->isAvailable();
	?>
	<?php if ( $rf_has_payment_fields && ! $rf_payments_ready ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: link to the Accept payments screen. */
						__( 'This form has payment fields. %s to start accepting payments — until then new submissions stay pending.', 'relevant-forms' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=relevant-payments' ) ) . '">' . esc_html__( 'Connect a Stripe account', 'relevant-forms' ) . '</a>'
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php /* Same embed code as the Forms list table; a new form has no ID yet, so the blurb stays hidden until the first save (the AJAX save reveals it in place, a reload re-renders it visible). */ ?>
	<p class="relevant-forms-embed-intro" id="rf-embed-intro"<?php echo esc_attr( $form->id > 0 ? '' : ' hidden' ); ?>>
		<?php
		echo wp_kses_post(
			sprintf(
				/* translators: %s: the form's embed shortcode, e.g. [relevant_form id="123"]. */
				__( 'To embed this form on a page, use the code %s.', 'relevant-forms' ),
				'<code>' . ( $form->id > 0 ? '[relevant_form id="' . esc_html( (string) $form->id ) . '"]' : '' ) . '</code>'
			)
		);
		?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="rf-form-editor-form">
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
			<tr>
				<th scope="row"><label for="relevant-form-currency"><?php esc_html_e( 'Payment currency', 'relevant-forms' ); ?></label></th>
				<td>
					<select id="relevant-form-currency" name="form_currency">
						<?php foreach ( \RelevantForms\Payments\Money::SUPPORTED_CURRENCIES as $relevant_currency ) : ?>
							<option value="<?php echo esc_attr( $relevant_currency ); ?>" <?php selected( $form->schema->currency(), $relevant_currency ); ?>><?php echo esc_html( \RelevantForms\Payments\Money::label( $relevant_currency ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Used by the payment fields on this form.', 'relevant-forms' ); ?></p>
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

		<?php $sender = $form->schema->sender(); ?>
		<?php $admin_emails = \RelevantForms\Support\SiteAdmins::emails(); ?>
		<h2><?php esc_html_e( 'Notifications', 'relevant-forms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="relevant-notification-emails"><?php esc_html_e( 'Email recipients', 'relevant-forms' ); ?></label></th>
				<td>
					<textarea class="relevant-forms-textarea" id="relevant-notification-emails" name="notification_emails" rows="6" placeholder="<?php esc_attr_e( 'one address per line', 'relevant-forms' ); ?>"><?php echo esc_textarea( implode( "\n", $form->schema->notificationEmails() ) ); ?></textarea>
					<p class="description relevant-forms-textarea-caption">
						<?php if ( [] !== $admin_emails ) : ?>
							<?php
							printf(
								/* translators: %s: administrator email addresses. */
								esc_html__( 'Leave empty to use the site administrator email (%s).', 'relevant-forms' ),
								esc_html( implode( ', ', $admin_emails ) )
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'Leave empty to use the site administrator email.', 'relevant-forms' ); ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-sender-name"><?php esc_html_e( 'Sender name', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="relevant-sender-name" name="sender_name" value="<?php echo esc_attr( $sender['name'] ); ?>">
					<p class="description"><?php esc_html_e( 'Shown as the sender of this form\'s notification emails.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-sender-email"><?php esc_html_e( 'Sender email', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="email" class="regular-text" id="relevant-sender-email" name="sender_email" value="<?php echo esc_attr( $sender['email'] ); ?>">
					<p class="description"><?php esc_html_e( 'Used together with the sender name; leave both empty to use the WordPress default.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<?php /* Hidden until the form exists: the backend renders the test from the saved form. */ ?>
			<?php if ( $form->id > 0 ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Send test email', 'relevant-forms' ); ?></th>
					<td>
						<textarea class="relevant-forms-textarea" id="relevant-test-email-to" rows="2" placeholder="<?php esc_attr_e( 'one address per line', 'relevant-forms' ); ?>"></textarea>
						<button type="button" class="button" id="rf-send-test-email"><?php esc_html_e( 'Send test email', 'relevant-forms' ); ?></button>
						<p class="rf-sms-message" id="rf-test-email-message" role="status" aria-live="polite" hidden></p>
						<p class="description"><?php esc_html_e( 'Sends one test message with this form\'s sender settings. Leave the addresses empty to send it to the site administrators; paste an external address (e.g. from a deliverability testing service) to check where emails land.', 'relevant-forms' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<?php
		$ai_policy    = $form->schema->aiPolicy();
		$privacy_on   = (bool) ( $ai_policy['privacy_mode'] ?? true );
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
			<p class="description"><?php esc_html_e( 'Requires a connected Relevant Cloud account (Relevant Forms → Settings) — turning this on and saving enables the classification consent for the site. Submissions are redacted before they are sent unless privacy mode is turned off below.', 'relevant-forms' ); ?></p>
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
			<tr class="rf-ai-dependent">
				<th scope="row"><?php esc_html_e( 'Privacy mode', 'relevant-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="ai_privacy_mode" value="1" <?php checked( $privacy_on ); ?>>
						<?php esc_html_e( 'Redact emails, phone numbers, IP addresses and URL paths before sending', 'relevant-forms' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Recommended. Disable only if classification quality suffers and you accept sending raw submissions.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr class="rf-ai-dependent">
				<th scope="row"><label for="relevant-ai-purpose"><?php esc_html_e( 'Form purpose', 'relevant-forms' ); ?></label></th>
				<td>
					<textarea class="relevant-forms-textarea" id="relevant-ai-purpose" name="ai_purpose" rows="4"><?php echo esc_textarea( (string) ( $ai_policy['purpose'] ?? '' ) ); ?></textarea>
					<p class="description relevant-forms-textarea-caption"><?php esc_html_e( 'What this form is about, so the AI can judge whether a submission is relevant, e.g. "Requests for quotes from homeowners", "RSVP for training events".', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr class="rf-ai-dependent">
				<th scope="row"><label for="relevant-ai-priorities"><?php esc_html_e( 'What needs immediate attention', 'relevant-forms' ); ?></label></th>
				<td>
					<textarea class="relevant-forms-textarea" id="relevant-ai-priorities" name="ai_priorities" rows="3" placeholder="<?php esc_attr_e( 'one per line', 'relevant-forms' ); ?>"><?php echo esc_textarea( (string) ( $ai_policy['priorities'] ?? '' ) ); ?></textarea>
					<p class="description relevant-forms-textarea-caption"><?php esc_html_e( 'Situations that should reach you right away, so the AI can judge urgency, e.g. "burst pipes", "no heating in winter". One per line.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr class="rf-ai-dependent">
				<th scope="row"></th>
				<td>
					<label>
						<input type="checkbox" id="relevant-ai-sms-alert" name="ai_sms_alert" value="1" <?php checked( $sms_alert_on ); ?>>
						<?php esc_html_e( 'Receive an SMS when a submission is particularly relevant (e.g. a request for a quote).', 'relevant-forms' ); ?>
					</label>
				</td>
			</tr>
			<tr class="rf-ai-dependent" id="relevant-ai-sms-recipient-row"<?php echo $sms_alert_on ? '' : ' hidden'; ?>>
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
			<tr class="rf-ai-dependent">
				<th scope="row"><label for="relevant-ai-spam"><?php esc_html_e( 'Unwanted enquiries', 'relevant-forms' ); ?></label></th>
				<td>
					<textarea class="relevant-forms-textarea" id="relevant-ai-spam" name="ai_spam" rows="4"><?php echo esc_textarea( (string) ( $ai_policy['spam'] ?? '' ) ); ?></textarea>
					<p class="description relevant-forms-textarea-caption"><?php esc_html_e( 'What to treat as spam or irrelevant for this form, e.g. "SEO services", "guest posts", "Automated sales pitches and link drops". One category per line.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
		</table>
		</div><!-- /.relevant-forms-ai-panel -->
		<?php $save_resume = $form->schema->saveResume(); ?>
		<h2><?php esc_html_e( 'Save and Resume', 'relevant-forms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable', 'relevant-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="save_resume_enabled" value="1" <?php checked( $save_resume['enabled'] ); ?>>
						<?php esc_html_e( 'Let visitors save their progress and resume later', 'relevant-forms' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Adds a "Save and Resume" button under the form: the visitor enters an email address and receives a link that restores their answers. Uploaded files are not saved and must be re-attached when resuming.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-save-resume-label"><?php esc_html_e( 'Button label', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="relevant-save-resume-label" name="save_resume_label" value="<?php echo esc_attr( $save_resume['label'] ); ?>" placeholder="<?php esc_attr_e( 'Save and Resume', 'relevant-forms' ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-save-resume-expiry"><?php esc_html_e( 'Resume link validity', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="number" class="small-text" id="relevant-save-resume-expiry" name="save_resume_expiry_days" min="1" max="365" step="1" value="<?php echo esc_attr( (string) $save_resume['expiry_days'] ); ?>">
					<?php esc_html_e( 'days', 'relevant-forms' ); ?>
					<p class="description"><?php esc_html_e( 'After this window the link stops working and the saved answers are deleted. Browser drafts expire at the same time.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Browser drafts', 'relevant-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="save_resume_drafts_in_browser" value="1" <?php checked( $save_resume['drafts_in_browser'] ); ?>>
						<?php esc_html_e( 'Keep a draft in the visitor\'s browser while they fill the form', 'relevant-forms' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Drafts never leave the visitor\'s device and are restored automatically on their next visit, until they submit or choose "Start fresh".', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-save-resume-subject"><?php esc_html_e( 'Resume email subject', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="relevant-save-resume-subject" name="save_resume_email_subject" value="<?php echo esc_attr( $save_resume['email_subject'] ); ?>" placeholder="<?php echo esc_attr( \RelevantForms\Notifications\ResumeEmail::DEFAULT_SUBJECT ); ?>">
					<p class="description"><?php esc_html_e( 'Leave empty to use the default subject shown as a hint.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-save-resume-body"><?php esc_html_e( 'Resume email message', 'relevant-forms' ); ?></label></th>
				<td>
					<textarea class="relevant-forms-textarea" id="relevant-save-resume-body" name="save_resume_email_body" rows="8" placeholder="<?php echo esc_attr( \RelevantForms\Notifications\ResumeEmail::DEFAULT_BODY ); ?>"><?php echo esc_textarea( $save_resume['email_body'] ); ?></textarea>
					<p class="description relevant-forms-textarea-caption"><?php esc_html_e( 'Leave empty to use the default text. Placeholders: {resume_link}, {form_name}, {expiry_days}, {site_name}. The link is appended automatically when {resume_link} is missing.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="submit rf-save rf-save--settings"><button type="submit" class="button button-primary"><?php echo esc_html( $rf_save_label ); ?></button></p>
		</div><!-- #rf-panel-settings -->

		<div id="rf-panel-fields" class="relevant-forms-tabs__panel">

		<?php
		$builder_fields = $form->schema->fields();
		$builder_items  = [];

		foreach ( $builder_fields as $builder_field_index => $builder_definition ) {
			$builder_field_type = RelevantForms\Forms\Fields\FieldTypeRegistry::get( (string) $builder_definition['type'] );

			if ( null !== $builder_field_type ) {
				$builder_items[ $builder_field_index ] = [ $builder_definition, $builder_field_type ];
			}
		}

		// The first field is always shown: its rules could only depend on
		// fields above it, and there are none. Ignore the stored ones.
		$builder_first_key = array_key_first( $builder_items );

		if ( null !== $builder_first_key ) {
			unset( $builder_items[ $builder_first_key ][0]['visibility'] );
		}
		?>
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
					foreach ( $builder_items as [ $definition, $field_type ] ) {
						$is_first = 0 === $builder_index;
						require RELEVANT_FORMS_PATH . 'templates/admin/field-list-item.php';
						++$builder_index;
					}
					?>
				</div>
				<p class="submit rf-save rf-save--list"><button type="submit" class="button button-primary"><?php echo esc_html( $rf_save_label ); ?></button></p>
			</div>
			<div class="rf-fields-column rf-fields-column--editor">
				<h2 id="rf-fields-title"><?php esc_html_e( 'Fields', 'relevant-forms' ); ?></h2>
				<p id="rf-fields-empty" class="rf-fields-empty"<?php echo [] === $builder_fields ? '' : ' hidden'; ?>><?php esc_html_e( 'Select a field to edit it, or add a new one.', 'relevant-forms' ); ?></p>
				<div id="rf-fields-editors">
					<?php
					foreach ( $builder_items as [ $definition, $field_type ] ) {
						require RELEVANT_FORMS_PATH . 'templates/admin/field-row.php';
					}
					?>
				</div>

				<h2 id="rf-fields-preview-title"<?php echo [] === $builder_fields ? ' hidden' : ''; ?>><?php esc_html_e( 'Preview', 'relevant-forms' ); ?></h2>
				<div id="rf-fields-preview" class="rf-fields-preview"<?php echo [] === $builder_fields ? ' hidden' : ''; ?>>
					<?php
					foreach ( $builder_items as [ $definition, $field_type ] ) {
						require RELEVANT_FORMS_PATH . 'templates/admin/field-preview.php';
					}
					?>
				</div>

				<h2 id="rf-fields-visibility-title"<?php echo [] === $builder_fields ? ' hidden' : ''; ?>><?php esc_html_e( 'Field visibility rules', 'relevant-forms' ); ?></h2>
				<div id="rf-fields-visibility" class="rf-fields-visibility"<?php echo [] === $builder_fields ? ' hidden' : ''; ?>>
					<?php
					foreach ( $builder_items as $builder_visibility_index => [ $definition, $field_type ] ) {
						$builder_visibility_is_first = array_key_first( $builder_items ) === $builder_visibility_index;
						require RELEVANT_FORMS_PATH . 'templates/admin/field-visibility.php';
					}
					?>
				</div>
				<p class="submit rf-save rf-save--editor"><button type="submit" class="button button-primary"><?php echo esc_html( $rf_save_label ); ?></button></p>
			</div>
		</div>
		</div><!-- #rf-panel-fields -->
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
			$builder_visibility_index    = 0;
			$builder_visibility_is_first = false;
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
