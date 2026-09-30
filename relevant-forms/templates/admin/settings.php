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

use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Cloud\LegalLinks;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Relevant Forms Settings', 'relevant-forms' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'relevant_forms_settings' ); ?>
		<table class="form-table" role="presentation">
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
			<?php esc_html_e( 'Where to store files uploaded via your forms: on your server (default) or on Relevant Cloud for enhanced security (requires an active subscription)', 'relevant-forms' ); ?>
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
			<?php esc_html_e( 'Basic scanning runs on this site in pure PHP (no services required). Deep scanning runs on the Relevant Cloud service for complex analysis — the Cloud connection is used automatically, with no separate endpoint or key. Deep local scanners are part of Relevant Pro.', 'relevant-forms' ); ?>
		</p>

		<h2><?php esc_html_e( 'Relevant Cloud', 'relevant-forms' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Relevant Cloud is an optional paid managed service (semantic spam analysis, priority SMS and managed secure uploads). No external request is made until you explicitly connect this site and enable a capability.', 'relevant-forms' ); ?>
		</p>

		<?php
		$connection        = new CloudConnection();
		$cloudState        = $connection->state();
		$cloudDisconnected = CloudConnection::STATE_DISCONNECTED === $cloudState;

		/**
		 * Print a settings notice when its status marker matches.
		 *
		 * @param array<string, array{0: string, 1: string}> $notices Notice map: status marker → [ tone, message ].
		 * @param string                                     $status  Active status marker.
		 */
		$renderNotice = static function ( array $notices, string $status ): void {
			if ( isset( $notices[ $status ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $notices[ $status ][0] ) . ' is-dismissible"><p>' . esc_html( $notices[ $status ][1] ) . '</p></div>';
			}
		};
		?>
		<table class="form-table" role="presentation">
			<?php $storedCloudKey = (string) ( $settings['cloud_api_key'] ?? '' ); ?>
			<?php if ( defined( 'RELEVANT_CLOUD_API_KEY' ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cloud API key', 'relevant-forms' ); ?></th>
					<td>
						<input type="password" class="regular-text" id="relevant-cloud-key" name="relevant_forms_settings[cloud_api_key]" value="" disabled autocomplete="new-password">
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'relevant-forms' ); ?></p>
						<p class="description"><?php esc_html_e( 'Issued by your Relevant Cloud dashboard. The service endpoint is built in.', 'relevant-forms' ); ?></p>
					</td>
				</tr>
			<?php elseif ( '' !== $storedCloudKey ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cloud API key', 'relevant-forms' ); ?></th>
					<td>
						<code><?php echo esc_html( CloudConfig::mask( $storedCloudKey ) ); ?></code>
						&middot; <a href="#relevant-cloud-key-new-row" id="relevant-cloud-key-edit" aria-controls="relevant-cloud-key-new-row" aria-expanded="false"><?php esc_html_e( 'Edit', 'relevant-forms' ); ?></a>
						<p class="description"><?php esc_html_e( 'A key from your Relevant Cloud dashboard is stored. The full key is never shown again.', 'relevant-forms' ); ?></p>
					</td>
				</tr>
				<tr id="relevant-cloud-key-new-row" hidden>
					<th scope="row"><label for="relevant-cloud-key"><?php esc_html_e( 'New key', 'relevant-forms' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="relevant-cloud-key" name="relevant_forms_settings[cloud_api_key]" value="" autocomplete="new-password">
						<p class="description"><?php esc_html_e( 'Paste a new key here if you want to replace the existing one. Leave blank to keep the current key.', 'relevant-forms' ); ?></p>
					</td>
				</tr>
			<?php else : ?>
				<tr>
					<th scope="row"><label for="relevant-cloud-key"><?php esc_html_e( 'Cloud API key', 'relevant-forms' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="relevant-cloud-key" name="relevant_forms_settings[cloud_api_key]" value="" autocomplete="new-password">
						<p class="description"><?php esc_html_e( 'Issued by your Relevant Cloud dashboard. The service endpoint is built in.', 'relevant-forms' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
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
					$legalLinks  = LegalLinks::all();
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

		<?php submit_button(); ?>
	</form>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status marker set by our own redirect.
	$cloudNotice = isset( $_GET['cloud'] ) ? sanitize_key( wp_unslash( $_GET['cloud'] ) ) : '';

	$renderNotice(
		[
			'connected'      => [ 'success', __( 'Connected to Relevant Cloud.', 'relevant-forms' ) ],
			'disconnected'   => [ 'info', __( 'Disconnected from Relevant Cloud. Core features continue to work.', 'relevant-forms' ) ],
			'failed'         => [ 'error', __( 'Could not connect to Relevant Cloud. Check the URL and API key.', 'relevant-forms' ) ],
			'not_configured' => [ 'error', __( 'A Cloud API key is required before connecting.', 'relevant-forms' ) ],
			'refreshed'      => [ 'success', __( 'Subscription and capabilities refreshed.', 'relevant-forms' ) ],
			'refresh_failed' => [ 'error', __( 'Could not refresh from Relevant Cloud. Check the connection and try again.', 'relevant-forms' ) ],
			'not_connected'  => [ 'error', __( 'This connection has no site record yet; reconnect with a dashboard-issued API key to manage subscriptions.', 'relevant-forms' ) ],
		],
		$cloudNotice
	);
	?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php if ( ! $cloudDisconnected ) : ?>
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
	$paymentsStatus    = $connection->payments();
	$paymentsConnected = ! empty( $paymentsStatus['connected'] );
	$paymentsClientOk  = CloudConfig::isConfigured() && ! $cloudDisconnected;
	$feePercent        = (float) ( $paymentsStatus['fee_percent'] ?? 2.0 );
	$feeLabel          = rtrim( rtrim( number_format( $feePercent, 2, '.', '' ), '0' ), '.' );
	?>

	<h2><?php esc_html_e( 'Payments (Stripe)', 'relevant-forms' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Accept payments on your forms through your own Stripe account (Stripe Connect). Buyers pay you directly on Stripe\'s hosted checkout; Relevant Cloud applies the platform fee below to each charge. Card data never touches this site.', 'relevant-forms' ); ?>
	</p>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status marker set by our own redirect.
	$paymentsNotice = isset( $_GET['payments'] ) ? sanitize_key( wp_unslash( $_GET['payments'] ) ) : '';
	?>
	<?php
	$renderNotice(
		[
			'connected'     => [ 'success', __( 'Stripe account connected. Payments are ready on every form with payment fields.', 'relevant-forms' ) ],
			'disconnected'  => [ 'info', __( 'Stripe account disconnected. Existing entries keep their payment records.', 'relevant-forms' ) ],
			'error'         => [ 'error', __( 'The Stripe authorization was not completed. No changes were made.', 'relevant-forms' ) ],
			'unavailable'   => [ 'error', __( 'Payments are not available on this deployment yet. Check the Cloud connection and try again.', 'relevant-forms' ) ],
			'not_connected' => [ 'error', __( 'Connect this site to Relevant Cloud first, then connect Stripe here.', 'relevant-forms' ) ],
		],
		$paymentsNotice
	);
	?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Stripe account', 'relevant-forms' ); ?></th>
			<td>
				<?php if ( $paymentsConnected ) : ?>
					<code><?php echo esc_html( (string) ( $paymentsStatus['stripe_user_id'] ?? '' ) ); ?></code>
					&middot; <strong><?php echo ! empty( $paymentsStatus['livemode'] ) ? esc_html__( 'Live mode', 'relevant-forms' ) : esc_html__( 'Test mode', 'relevant-forms' ); ?></strong>
					<?php if ( empty( $paymentsStatus['charges_enabled'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'Stripe reports charges as disabled for this account — finish the Stripe onboarding to accept payments.', 'relevant-forms' ); ?></p>
					<?php endif; ?>
				<?php else : ?>
					<span><?php esc_html_e( 'No Stripe account connected.', 'relevant-forms' ); ?></span>
				<?php endif; ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: platform fee percentage */
						esc_html__( 'Platform fee: %s%% of each charge, collected automatically by Stripe. Payouts and refunds happen on your own Stripe account.', 'relevant-forms' ),
						esc_html( $feeLabel )
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Connection', 'relevant-forms' ); ?></th>
			<td>
				<?php if ( ! $paymentsClientOk ) : ?>
					<p class="description"><?php esc_html_e( 'Connect this site to Relevant Cloud first (above), then connect Stripe here.', 'relevant-forms' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
						<input type="hidden" name="action" value="relevant_forms_payments_connect">
						<?php wp_nonce_field( 'relevant_payments_connect' ); ?>
						<button type="submit" class="button button-primary"><?php echo $paymentsConnected ? esc_html__( 'Reconnect Stripe', 'relevant-forms' ) : esc_html__( 'Connect Stripe', 'relevant-forms' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
						<input type="hidden" name="action" value="relevant_forms_payments_refresh">
						<?php wp_nonce_field( 'relevant_payments_refresh' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Refresh status', 'relevant-forms' ); ?></button>
					</form>
					<?php if ( $paymentsConnected ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
							<input type="hidden" name="action" value="relevant_forms_payments_disconnect">
							<?php wp_nonce_field( 'relevant_payments_disconnect' ); ?>
							<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Disconnect Stripe', 'relevant-forms' ); ?></button>
						</form>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Connecting opens Stripe: you choose (or create) the account that receives the money. Access can be revoked here or from your Stripe dashboard at any time.', 'relevant-forms' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
	</table>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status marker set by our own redirect.
	$billingNotice = isset( $_GET['billing'] ) ? sanitize_key( wp_unslash( $_GET['billing'] ) ) : '';

	$renderNotice(
		[
			'not_connected'  => [ 'error', __( 'Connect this site to Relevant Cloud before managing a subscription.', 'relevant-forms' ) ],
			'not_configured' => [ 'error', __( 'A Cloud API key is required before managing a subscription.', 'relevant-forms' ) ],
			'unavailable'    => [ 'error', __( 'This connection has no Relevant Cloud dashboard link yet. Reconnect with a dashboard-issued API key to manage billing.', 'relevant-forms' ) ],
		],
		$billingNotice
	);
	?>

	<h2><?php esc_html_e( 'Subscription', 'relevant-forms' ); ?></h2>
	<?php $subscription = $connection->subscription(); ?>
	<?php if ( $cloudDisconnected ) : ?>
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
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
						<input type="hidden" name="action" value="relevant_forms_cloud_refresh">
						<?php wp_nonce_field( 'relevant_cloud_refresh' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Refresh subscription', 'relevant-forms' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'Plan changes, payment details and invoices live in the Relevant Cloud dashboard; the subscription state shown here is read-only.', 'relevant-forms' ); ?></p>
				</td>
			</tr>
		</table>
	<?php endif; ?>
</div>
