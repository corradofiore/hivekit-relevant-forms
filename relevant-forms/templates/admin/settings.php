<?php
/**
 * Settings template.
 *
 * @package RelevantForms
 *
 * @var array<string, mixed> $settings Current settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Relevant Forms Settings', 'relevant-forms' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'relevant_forms_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="relevant-sender-name"><?php esc_html_e( 'Notification sender name', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="relevant-sender-name" name="relevant_forms_settings[notification_sender_name]" value="<?php echo esc_attr( (string) ( $settings['notification_sender_name'] ?? '' ) ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-sender-email"><?php esc_html_e( 'Notification sender email', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="email" class="regular-text" id="relevant-sender-email" name="relevant_forms_settings[notification_sender_email]" value="<?php echo esc_attr( (string) ( $settings['notification_sender_email'] ?? '' ) ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Debug logging', 'relevant-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="relevant_forms_settings[debug_logging]" value="1" <?php checked( ! empty( $settings['debug_logging'] ) ); ?>>
						<?php esc_html_e( 'Enable debug logging (records detailed info events)', 'relevant-forms' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'File storage', 'relevant-forms' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Uploads are stored privately. Choose local private storage or Relevant Managed Secure Storage (Cloud). Bring-your-own object storage is part of Relevant Pro. Constants in wp-config.php override these settings.', 'relevant-forms' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="relevant-storage-provider"><?php esc_html_e( 'Provider', 'relevant-forms' ); ?></label></th>
				<td>
					<select id="relevant-storage-provider" name="relevant_forms_settings[storage_provider]" <?php disabled( defined( 'RELEVANT_STORAGE_PROVIDER' ) ); ?>>
						<option value="local" <?php selected( (string) ( $settings['storage_provider'] ?? '' ), 'local' ); ?>><?php esc_html_e( 'Local (private server directory)', 'relevant-forms' ); ?></option>
						<option value="cloud" <?php selected( (string) ( $settings['storage_provider'] ?? '' ), 'cloud' ); ?>><?php esc_html_e( 'Relevant Managed Secure Storage (Cloud)', 'relevant-forms' ); ?></option>
					</select>
					<?php if ( defined( 'RELEVANT_STORAGE_PROVIDER' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'relevant-forms' ); ?></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Relevant Managed Secure Storage requires a connected Cloud account with the managed uploads capability; until then uploads fall back to local storage. Bytes go directly to private cloud storage and are scanned on the backend before any download.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Downloads', 'relevant-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="relevant_forms_settings[storage_block_unscanned_downloads]" value="1" <?php checked( ! empty( $settings['storage_block_unscanned_downloads'] ) ); ?>>
						<?php esc_html_e( 'Block downloads of files that have not been scanned yet', 'relevant-forms' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Malware scanning', 'relevant-forms' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Basic scanning runs on this site in pure PHP (no services required). Deep scanning is queued on the Relevant backend for complex analysis; this site only acts as an API client. Deep local scanners are part of Relevant Pro.', 'relevant-forms' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="relevant-backend-url"><?php esc_html_e( 'Scan backend URL', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="relevant-backend-url" name="relevant_forms_settings[scan_backend_url]" value="<?php echo esc_attr( (string) ( $settings['scan_backend_url'] ?? '' ) ); ?>" <?php disabled( defined( 'RELEVANT_SCAN_BACKEND_URL' ) ); ?> placeholder="https://api.example.com">
					<?php if ( defined( 'RELEVANT_SCAN_BACKEND_URL' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'relevant-forms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-backend-key"><?php esc_html_e( 'Scan backend API key', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="relevant-backend-key" name="relevant_forms_settings[scan_backend_key]" value="" <?php disabled( defined( 'RELEVANT_SCAN_BACKEND_KEY' ) ); ?> autocomplete="new-password">
					<?php if ( defined( 'RELEVANT_SCAN_BACKEND_KEY' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'relevant-forms' ); ?></p>
					<?php elseif ( '' !== (string) ( $settings['scan_backend_key'] ?? '' ) ) : ?>
						<p class="description"><?php esc_html_e( 'A key is stored. Leave blank to keep it.', 'relevant-forms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Relevant Cloud', 'relevant-forms' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Relevant Cloud is an optional paid managed service (semantic spam analysis, priority SMS and managed secure uploads). No external request is made until you explicitly connect this site and enable a capability.', 'relevant-forms' ); ?>
		</p>

		<?php
		$connection = new \RelevantForms\Cloud\CloudConnection();
		$cloudState = $connection->state();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="relevant-cloud-url"><?php esc_html_e( 'Cloud URL', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="relevant-cloud-url" name="relevant_forms_settings[cloud_url]" value="<?php echo esc_attr( (string) ( $settings['cloud_url'] ?? '' ) ); ?>" <?php disabled( defined( 'RELEVANT_CLOUD_URL' ) ); ?> placeholder="https://cloud.example.com">
					<?php if ( defined( 'RELEVANT_CLOUD_URL' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'relevant-forms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-cloud-key"><?php esc_html_e( 'Cloud API key', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="relevant-cloud-key" name="relevant_forms_settings[cloud_api_key]" value="" <?php disabled( defined( 'RELEVANT_CLOUD_API_KEY' ) ); ?> autocomplete="new-password">
					<?php if ( defined( 'RELEVANT_CLOUD_API_KEY' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'relevant-forms' ); ?></p>
					<?php elseif ( '' !== (string) ( $settings['cloud_api_key'] ?? '' ) ) : ?>
						<p class="description"><?php esc_html_e( 'A key is stored. Leave blank to keep it.', 'relevant-forms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Connection state', 'relevant-forms' ); ?></th>
				<td>
					<code><?php echo esc_html( $cloudState ); ?></code>
					<?php if ( null !== $connection->siteId() ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: cloud site id */
								esc_html__( 'Site ID: %s', 'relevant-forms' ),
								esc_html( (string) $connection->siteId() )
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Data & privacy', 'relevant-forms' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'Enabled capabilities send only the data they need: redacted submission text for classification, the alert summary and your mobile number for SMS, and the file itself for managed secure storage and scanning. Disconnecting stops all external calls.', 'relevant-forms' ); ?></p>
					<?php
					$legalLinks  = \RelevantForms\Cloud\LegalLinks::all();
					$legalLabels = [
						'terms'         => __( 'Terms of service', 'relevant-forms' ),
						'privacy'       => __( 'Privacy policy', 'relevant-forms' ),
						'subprocessors' => __( 'Subprocessors', 'relevant-forms' ),
					];
					?>
					<?php if ( [] !== $legalLinks ) : ?>
						<p>
							<?php
							$legalItems = [];

							foreach ( $legalLinks as $legalKey => $legalUrl ) {
								if ( ! isset( $legalLabels[ $legalKey ] ) ) {
									continue;
								}

								$legalItems[] = sprintf(
									'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
									esc_url( (string) $legalUrl ),
									esc_html( $legalLabels[ $legalKey ] )
								);
							}

							echo wp_kses_post( implode( ' &middot; ', $legalItems ) );
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'AI classification', 'relevant-forms' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Semantic classification runs on Relevant Cloud. Nothing is sent until you connect the site above, enable the capability here and opt forms in individually.', 'relevant-forms' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable classification', 'relevant-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="relevant_forms_settings[ai_enabled]" value="1" <?php checked( $connection->capabilityEnabled( \RelevantForms\Cloud\CloudConnection::CAP_AI ) ); ?> <?php disabled( \RelevantForms\Cloud\CloudConnection::STATE_DISCONNECTED === $cloudState ); ?>>
						<?php esc_html_e( 'Let Relevant Cloud classify new submissions', 'relevant-forms' ); ?>
					</label>
					<?php if ( \RelevantForms\Cloud\CloudConnection::STATE_DISCONNECTED === $cloudState ) : ?>
						<p class="description"><?php esc_html_e( 'Connect to Relevant Cloud first.', 'relevant-forms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-ai-site-purpose"><?php esc_html_e( 'Site purpose', 'relevant-forms' ); ?></label></th>
				<td>
					<textarea class="large-text" id="relevant-ai-site-purpose" name="relevant_forms_settings[ai_site_purpose]" rows="2"><?php echo esc_textarea( (string) ( $settings['ai_site_purpose'] ?? '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Short description of the site, e.g. "Plumbing company in Leeds".', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-ai-priorities"><?php esc_html_e( 'Priorities', 'relevant-forms' ); ?></label></th>
				<td>
					<textarea class="large-text" id="relevant-ai-priorities" name="relevant_forms_settings[ai_priorities]" rows="3" placeholder="<?php esc_attr_e( 'one per line', 'relevant-forms' ); ?>"><?php echo esc_textarea( (string) ( $settings['ai_priorities'] ?? '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'What counts as high priority, e.g. "emergency repairs", "quote requests".', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-ai-unwanted"><?php esc_html_e( 'Unwanted enquiries', 'relevant-forms' ); ?></label></th>
				<td>
					<textarea class="large-text" id="relevant-ai-unwanted" name="relevant_forms_settings[ai_unwanted]" rows="3" placeholder="<?php esc_attr_e( 'one per line', 'relevant-forms' ); ?>"><?php echo esc_textarea( (string) ( $settings['ai_unwanted'] ?? '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'What to treat as spam or irrelevant, e.g. "SEO services", "guest posts".', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Privacy mode', 'relevant-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="relevant_forms_settings[ai_privacy_mode]" value="1" <?php checked( ! empty( $settings['ai_privacy_mode'] ?? 1 ) ); ?>>
						<?php esc_html_e( 'Redact emails, phone numbers, IP addresses and URL paths before sending', 'relevant-forms' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Recommended. Disable only if classification quality suffers and you accept sending raw submissions.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="relevant-sms-recipient"><?php esc_html_e( 'Priority SMS recipient', 'relevant-forms' ); ?></label></th>
				<td>
					<input type="tel" class="regular-text" id="relevant-sms-recipient" name="relevant_forms_settings[sms_recipient]" value="<?php echo esc_attr( (string) ( $settings['sms_recipient'] ?? '' ) ); ?>" placeholder="+39 333 1234567">
					<p class="description"><?php esc_html_e( 'Mobile number that receives managed priority alerts.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Priority SMS alerts', 'relevant-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="relevant_forms_settings[sms_enabled]" value="1" <?php checked( $connection->capabilityEnabled( \RelevantForms\Cloud\CloudConnection::CAP_SMS ) ); ?> <?php disabled( \RelevantForms\Cloud\CloudConnection::STATE_DISCONNECTED === $cloudState ); ?>>
						<?php esc_html_e( 'SMS me when a submission is genuinely urgent (attention >= 85)', 'relevant-forms' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Never sends the full submission: only the AI summary and a link to the entry (WordPress login required).', 'relevant-forms' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status marker set by our own redirect.
	$cloudNotice = isset( $_GET['cloud'] ) ? sanitize_key( wp_unslash( $_GET['cloud'] ) ) : '';

	if ( 'connected' === $cloudNotice ) :
		?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Connected to Relevant Cloud.', 'relevant-forms' ); ?></p></div>
	<?php elseif ( 'disconnected' === $cloudNotice ) : ?>
		<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Disconnected from Relevant Cloud. Core features continue to work.', 'relevant-forms' ); ?></p></div>
	<?php elseif ( 'failed' === $cloudNotice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not connect to Relevant Cloud. Check the URL and API key.', 'relevant-forms' ); ?></p></div>
	<?php elseif ( 'not_configured' === $cloudNotice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Cloud URL and API key are required before connecting.', 'relevant-forms' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php if ( \RelevantForms\Cloud\CloudConnection::STATE_DISCONNECTED !== $cloudState ) : ?>
			<input type="hidden" name="action" value="relevant_forms_cloud_disconnect">
			<?php wp_nonce_field( 'relevant_cloud_disconnect' ); ?>
			<?php submit_button( __( 'Disconnect from Cloud', 'relevant-forms' ), 'secondary', 'submit', false ); ?>
		<?php else : ?>
			<input type="hidden" name="action" value="relevant_forms_cloud_connect">
			<?php wp_nonce_field( 'relevant_cloud_connect' ); ?>
			<?php submit_button( __( 'Connect to Cloud', 'relevant-forms' ), 'primary', 'submit', false ); ?>
		<?php endif; ?>
	</form>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status marker set by our own redirect.
	$billingNotice = isset( $_GET['billing'] ) ? sanitize_key( wp_unslash( $_GET['billing'] ) ) : '';

	if ( 'not_connected' === $billingNotice ) :
		?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Connect this site to Relevant Cloud before managing a subscription.', 'relevant-forms' ); ?></p></div>
	<?php elseif ( 'not_configured' === $billingNotice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Cloud URL and API key are required before managing a subscription.', 'relevant-forms' ); ?></p></div>
	<?php elseif ( 'unavailable' === $billingNotice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'This connection has no Relevant Cloud dashboard link yet. Reconnect with a dashboard-issued API key to manage billing.', 'relevant-forms' ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Subscription', 'relevant-forms' ); ?></h2>
	<?php $subscription = $connection->subscription(); ?>
	<?php if ( \RelevantForms\Cloud\CloudConnection::STATE_DISCONNECTED === $cloudState ) : ?>
		<p><?php esc_html_e( 'Connect to Relevant Cloud to subscribe or manage your plan.', 'relevant-forms' ); ?></p>
	<?php else : ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Current plan', 'relevant-forms' ); ?></th>
				<td>
					<?php if ( [] === $subscription ) : ?>
						<code><?php esc_html_e( 'unknown — reconnect or refresh capabilities', 'relevant-forms' ); ?></code>
					<?php else : ?>
						<code><?php echo esc_html( (string) ( $subscription['plan'] ?? 'none' ) ); ?></code>
						&middot; <code><?php echo esc_html( (string) ( $subscription['status'] ?? 'unknown' ) ); ?></code>
						<?php if ( ! empty( $subscription['ends_at'] ) ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: subscription end date */
									esc_html__( 'Access ends %s.', 'relevant-forms' ),
									esc_html( (string) $subscription['ends_at'] )
								);
								?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Billing', 'relevant-forms' ); ?></th>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
						<input type="hidden" name="action" value="relevant_forms_cloud_billing">
						<?php wp_nonce_field( 'relevant_cloud_billing' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Manage billing', 'relevant-forms' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'Plan changes, payment details and invoices live in the Relevant Cloud dashboard; the subscription state shown here is read-only.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
		</table>
	<?php endif; ?>
</div>
