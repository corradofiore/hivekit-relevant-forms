<?php
/**
 * Settings template.
 *
 * @package SentinelForms
 *
 * @var array<string, mixed> $settings Current settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Sentinel Forms Settings', 'sentinel-forms' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'sentinel_forms_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sentinel-sender-name"><?php esc_html_e( 'Notification sender name', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="sentinel-sender-name" name="sentinel_forms_settings[notification_sender_name]" value="<?php echo esc_attr( (string) ( $settings['notification_sender_name'] ?? '' ) ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-sender-email"><?php esc_html_e( 'Notification sender email', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="email" class="regular-text" id="sentinel-sender-email" name="sentinel_forms_settings[notification_sender_email]" value="<?php echo esc_attr( (string) ( $settings['notification_sender_email'] ?? '' ) ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Debug logging', 'sentinel-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="sentinel_forms_settings[debug_logging]" value="1" <?php checked( ! empty( $settings['debug_logging'] ) ); ?>>
						<?php esc_html_e( 'Enable debug logging (records detailed info events)', 'sentinel-forms' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'File storage', 'sentinel-forms' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Uploads are stored privately. Choose local private storage or Sentinel Managed Secure Storage (Cloud). Bring-your-own object storage is part of Sentinel Pro. Constants in wp-config.php override these settings.', 'sentinel-forms' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sentinel-storage-provider"><?php esc_html_e( 'Provider', 'sentinel-forms' ); ?></label></th>
				<td>
					<select id="sentinel-storage-provider" name="sentinel_forms_settings[storage_provider]" <?php disabled( defined( 'SENTINEL_STORAGE_PROVIDER' ) ); ?>>
						<option value="local" <?php selected( (string) ( $settings['storage_provider'] ?? '' ), 'local' ); ?>><?php esc_html_e( 'Local (private server directory)', 'sentinel-forms' ); ?></option>
						<option value="cloud" <?php selected( (string) ( $settings['storage_provider'] ?? '' ), 'cloud' ); ?>><?php esc_html_e( 'Sentinel Managed Secure Storage (Cloud)', 'sentinel-forms' ); ?></option>
					</select>
					<?php if ( defined( 'SENTINEL_STORAGE_PROVIDER' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'sentinel-forms' ); ?></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Sentinel Managed Secure Storage requires a connected Cloud account with the managed uploads capability; until then uploads fall back to local storage. Bytes go directly to private cloud storage and are scanned on the backend before any download.', 'sentinel-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Downloads', 'sentinel-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="sentinel_forms_settings[storage_block_unscanned_downloads]" value="1" <?php checked( ! empty( $settings['storage_block_unscanned_downloads'] ) ); ?>>
						<?php esc_html_e( 'Block downloads of files that have not been scanned yet', 'sentinel-forms' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Malware scanning', 'sentinel-forms' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Basic scanning runs on this site in pure PHP (no services required). Deep scanning is queued on the Sentinel backend for complex analysis; this site only acts as an API client. Deep local scanners are part of Sentinel Pro.', 'sentinel-forms' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sentinel-backend-url"><?php esc_html_e( 'Scan backend URL', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="sentinel-backend-url" name="sentinel_forms_settings[scan_backend_url]" value="<?php echo esc_attr( (string) ( $settings['scan_backend_url'] ?? '' ) ); ?>" <?php disabled( defined( 'SENTINEL_SCAN_BACKEND_URL' ) ); ?> placeholder="https://api.example.com">
					<?php if ( defined( 'SENTINEL_SCAN_BACKEND_URL' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'sentinel-forms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-backend-key"><?php esc_html_e( 'Scan backend API key', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="sentinel-backend-key" name="sentinel_forms_settings[scan_backend_key]" value="" <?php disabled( defined( 'SENTINEL_SCAN_BACKEND_KEY' ) ); ?> autocomplete="new-password">
					<?php if ( defined( 'SENTINEL_SCAN_BACKEND_KEY' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'sentinel-forms' ); ?></p>
					<?php elseif ( '' !== (string) ( $settings['scan_backend_key'] ?? '' ) ) : ?>
						<p class="description"><?php esc_html_e( 'A key is stored. Leave blank to keep it.', 'sentinel-forms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Sentinel Cloud', 'sentinel-forms' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Sentinel Cloud is an optional paid managed service (semantic spam analysis, priority SMS and managed secure uploads). No external request is made until you explicitly connect this site and enable a capability.', 'sentinel-forms' ); ?>
		</p>

		<?php
		$connection = new \SentinelForms\Cloud\CloudConnection();
		$cloudState = $connection->state();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sentinel-cloud-url"><?php esc_html_e( 'Cloud URL', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="sentinel-cloud-url" name="sentinel_forms_settings[cloud_url]" value="<?php echo esc_attr( (string) ( $settings['cloud_url'] ?? '' ) ); ?>" <?php disabled( defined( 'SENTINEL_CLOUD_URL' ) ); ?> placeholder="https://cloud.example.com">
					<?php if ( defined( 'SENTINEL_CLOUD_URL' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'sentinel-forms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-cloud-key"><?php esc_html_e( 'Cloud API key', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="sentinel-cloud-key" name="sentinel_forms_settings[cloud_api_key]" value="" <?php disabled( defined( 'SENTINEL_CLOUD_API_KEY' ) ); ?> autocomplete="new-password">
					<?php if ( defined( 'SENTINEL_CLOUD_API_KEY' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'sentinel-forms' ); ?></p>
					<?php elseif ( '' !== (string) ( $settings['cloud_api_key'] ?? '' ) ) : ?>
						<p class="description"><?php esc_html_e( 'A key is stored. Leave blank to keep it.', 'sentinel-forms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Connection state', 'sentinel-forms' ); ?></th>
				<td>
					<code><?php echo esc_html( $cloudState ); ?></code>
					<?php if ( null !== $connection->siteId() ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: cloud site id */
								esc_html__( 'Site ID: %s', 'sentinel-forms' ),
								esc_html( (string) $connection->siteId() )
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Data & privacy', 'sentinel-forms' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'Enabled capabilities send only the data they need: redacted submission text for classification, the alert summary and your mobile number for SMS, and the file itself for managed secure storage and scanning. Disconnecting stops all external calls.', 'sentinel-forms' ); ?></p>
					<?php
					$legalLinks  = \SentinelForms\Cloud\LegalLinks::all();
					$legalLabels = [
						'terms'         => __( 'Terms of service', 'sentinel-forms' ),
						'privacy'       => __( 'Privacy policy', 'sentinel-forms' ),
						'subprocessors' => __( 'Subprocessors', 'sentinel-forms' ),
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

		<h2><?php esc_html_e( 'AI classification', 'sentinel-forms' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Semantic classification runs on Sentinel Cloud. Nothing is sent until you connect the site above, enable the capability here and opt forms in individually.', 'sentinel-forms' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable classification', 'sentinel-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="sentinel_forms_settings[ai_enabled]" value="1" <?php checked( $connection->capabilityEnabled( \SentinelForms\Cloud\CloudConnection::CAP_AI ) ); ?> <?php disabled( \SentinelForms\Cloud\CloudConnection::STATE_DISCONNECTED === $cloudState ); ?>>
						<?php esc_html_e( 'Let Sentinel Cloud classify new submissions', 'sentinel-forms' ); ?>
					</label>
					<?php if ( \SentinelForms\Cloud\CloudConnection::STATE_DISCONNECTED === $cloudState ) : ?>
						<p class="description"><?php esc_html_e( 'Connect to Sentinel Cloud first.', 'sentinel-forms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-ai-site-purpose"><?php esc_html_e( 'Site purpose', 'sentinel-forms' ); ?></label></th>
				<td>
					<textarea class="large-text" id="sentinel-ai-site-purpose" name="sentinel_forms_settings[ai_site_purpose]" rows="2"><?php echo esc_textarea( (string) ( $settings['ai_site_purpose'] ?? '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Short description of the site, e.g. "Plumbing company in Leeds".', 'sentinel-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-ai-priorities"><?php esc_html_e( 'Priorities', 'sentinel-forms' ); ?></label></th>
				<td>
					<textarea class="large-text" id="sentinel-ai-priorities" name="sentinel_forms_settings[ai_priorities]" rows="3" placeholder="<?php esc_attr_e( 'one per line', 'sentinel-forms' ); ?>"><?php echo esc_textarea( (string) ( $settings['ai_priorities'] ?? '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'What counts as high priority, e.g. "emergency repairs", "quote requests".', 'sentinel-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-ai-unwanted"><?php esc_html_e( 'Unwanted enquiries', 'sentinel-forms' ); ?></label></th>
				<td>
					<textarea class="large-text" id="sentinel-ai-unwanted" name="sentinel_forms_settings[ai_unwanted]" rows="3" placeholder="<?php esc_attr_e( 'one per line', 'sentinel-forms' ); ?>"><?php echo esc_textarea( (string) ( $settings['ai_unwanted'] ?? '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'What to treat as spam or irrelevant, e.g. "SEO services", "guest posts".', 'sentinel-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Privacy mode', 'sentinel-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="sentinel_forms_settings[ai_privacy_mode]" value="1" <?php checked( ! empty( $settings['ai_privacy_mode'] ?? 1 ) ); ?>>
						<?php esc_html_e( 'Redact emails, phone numbers, IP addresses and URL paths before sending', 'sentinel-forms' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Recommended. Disable only if classification quality suffers and you accept sending raw submissions.', 'sentinel-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-sms-recipient"><?php esc_html_e( 'Priority SMS recipient', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="tel" class="regular-text" id="sentinel-sms-recipient" name="sentinel_forms_settings[sms_recipient]" value="<?php echo esc_attr( (string) ( $settings['sms_recipient'] ?? '' ) ); ?>" placeholder="+39 333 1234567">
					<p class="description"><?php esc_html_e( 'Mobile number that receives managed priority alerts.', 'sentinel-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Priority SMS alerts', 'sentinel-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="sentinel_forms_settings[sms_enabled]" value="1" <?php checked( $connection->capabilityEnabled( \SentinelForms\Cloud\CloudConnection::CAP_SMS ) ); ?> <?php disabled( \SentinelForms\Cloud\CloudConnection::STATE_DISCONNECTED === $cloudState ); ?>>
						<?php esc_html_e( 'SMS me when a submission is genuinely urgent (attention >= 85)', 'sentinel-forms' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Never sends the full submission: only the AI summary and a link to the entry (WordPress login required).', 'sentinel-forms' ); ?></p>
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
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Connected to Sentinel Cloud.', 'sentinel-forms' ); ?></p></div>
	<?php elseif ( 'disconnected' === $cloudNotice ) : ?>
		<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Disconnected from Sentinel Cloud. Core features continue to work.', 'sentinel-forms' ); ?></p></div>
	<?php elseif ( 'failed' === $cloudNotice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not connect to Sentinel Cloud. Check the URL and API key.', 'sentinel-forms' ); ?></p></div>
	<?php elseif ( 'not_configured' === $cloudNotice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Cloud URL and API key are required before connecting.', 'sentinel-forms' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php if ( \SentinelForms\Cloud\CloudConnection::STATE_DISCONNECTED !== $cloudState ) : ?>
			<input type="hidden" name="action" value="sentinel_forms_cloud_disconnect">
			<?php wp_nonce_field( 'sentinel_cloud_disconnect' ); ?>
			<?php submit_button( __( 'Disconnect from Cloud', 'sentinel-forms' ), 'secondary', 'submit', false ); ?>
		<?php else : ?>
			<input type="hidden" name="action" value="sentinel_forms_cloud_connect">
			<?php wp_nonce_field( 'sentinel_cloud_connect' ); ?>
			<?php submit_button( __( 'Connect to Cloud', 'sentinel-forms' ), 'primary', 'submit', false ); ?>
		<?php endif; ?>
	</form>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status marker set by our own redirect.
	$billingNotice = isset( $_GET['billing'] ) ? sanitize_key( wp_unslash( $_GET['billing'] ) ) : '';

	if ( 'return' === $billingNotice ) :
		?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Billing session finished. Subscription changes appear below after the payment provider confirms them.', 'sentinel-forms' ); ?></p></div>
	<?php elseif ( 'cancel' === $billingNotice ) : ?>
		<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Billing session cancelled. No changes were made.', 'sentinel-forms' ); ?></p></div>
	<?php elseif ( 'failed' === $billingNotice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not open the billing session. Please try again or contact support.', 'sentinel-forms' ); ?></p></div>
	<?php elseif ( 'not_connected' === $billingNotice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Connect this site to Sentinel Cloud before managing a subscription.', 'sentinel-forms' ); ?></p></div>
	<?php elseif ( 'not_configured' === $billingNotice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Cloud URL and API key are required before managing a subscription.', 'sentinel-forms' ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Subscription', 'sentinel-forms' ); ?></h2>
	<?php $subscription = $connection->subscription(); ?>
	<?php if ( \SentinelForms\Cloud\CloudConnection::STATE_DISCONNECTED === $cloudState ) : ?>
		<p><?php esc_html_e( 'Connect to Sentinel Cloud to subscribe or manage your plan.', 'sentinel-forms' ); ?></p>
	<?php else : ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Current plan', 'sentinel-forms' ); ?></th>
				<td>
					<?php if ( [] === $subscription ) : ?>
						<code><?php esc_html_e( 'unknown — reconnect or refresh capabilities', 'sentinel-forms' ); ?></code>
					<?php else : ?>
						<code><?php echo esc_html( (string) ( $subscription['plan'] ?? 'none' ) ); ?></code>
						&middot; <code><?php echo esc_html( (string) ( $subscription['status'] ?? 'unknown' ) ); ?></code>
						<?php if ( ! empty( $subscription['ends_at'] ) ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: subscription end date */
									esc_html__( 'Access ends %s.', 'sentinel-forms' ),
									esc_html( (string) $subscription['ends_at'] )
								);
								?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Billing', 'sentinel-forms' ); ?></th>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5em;">
						<input type="hidden" name="action" value="sentinel_forms_cloud_billing">
						<input type="hidden" name="mode" value="checkout">
						<input type="hidden" name="plan" value="cloud">
						<?php wp_nonce_field( 'sentinel_cloud_billing' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Subscribe / upgrade', 'sentinel-forms' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
						<input type="hidden" name="action" value="sentinel_forms_cloud_billing">
						<input type="hidden" name="mode" value="portal">
						<?php wp_nonce_field( 'sentinel_cloud_billing' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Manage billing', 'sentinel-forms' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'Payments, invoices and receipts are handled by the billing provider (merchant of record); card data never touches this site.', 'sentinel-forms' ); ?></p>
				</td>
			</tr>
		</table>
	<?php endif; ?>
</div>
