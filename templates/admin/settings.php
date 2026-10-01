<?php
/**
 * Settings template: Cloud subscription, file storage and payments
 * sections under three tabs sharing one options form.
 *
 * The nonced action buttons inside the panels (connect/disconnect,
 * billing, Stripe) submit forms declared after the options form: HTML
 * forbids nested forms, so each button is associated with its action form
 * via the form attribute instead.
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
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Relevant Forms Settings', 'relevant-forms' ); ?></h1>
	<?php settings_errors(); ?>

	<?php
	$connection        = new CloudConnection();
	$cloudState        = $connection->state();
	$cloudDisconnected = CloudConnection::STATE_DISCONNECTED === $cloudState;

	$paymentsStatus    = $connection->payments();
	$paymentsConnected = ! empty( $paymentsStatus['connected'] );
	$paymentsClientOk  = CloudConfig::isConfigured() && ! $cloudDisconnected;
	$feePercent        = (float) ( $paymentsStatus['fee_percent'] ?? 2.0 );
	$feeLabel          = rtrim( rtrim( number_format( $feePercent, 2, '.', '' ), '0' ), '.' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status marker set by our own redirect.
	$cloudNotice = isset( $_GET['cloud'] ) ? sanitize_key( wp_unslash( $_GET['cloud'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status marker set by our own redirect.
	$paymentsNotice = isset( $_GET['payments'] ) ? sanitize_key( wp_unslash( $_GET['payments'] ) ) : '';

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

	<form method="post" action="options.php">
		<?php settings_fields( 'relevant_forms_settings' ); ?>

		<nav class="nav-tab-wrapper relevant-forms-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'relevant-forms' ); ?>">
			<a class="nav-tab nav-tab-active relevant-forms-tabs__tab" href="<?php echo esc_url( '#rf-panel-cloud' ); ?>"><?php esc_html_e( 'Relevant Cloud subscription', 'relevant-forms' ); ?></a>
			<a class="nav-tab relevant-forms-tabs__tab" href="<?php echo esc_url( '#rf-panel-storage' ); ?>"><?php esc_html_e( 'File storage', 'relevant-forms' ); ?></a>
			<a class="nav-tab relevant-forms-tabs__tab" href="<?php echo esc_url( '#rf-panel-payments' ); ?>"><?php esc_html_e( 'Payments configuration (Stripe)', 'relevant-forms' ); ?></a>
		</nav>

		<div id="rf-panel-cloud" class="relevant-forms-tabs__panel">
			<p class="description">
				<?php esc_html_e( 'Relevant Cloud is an optional paid managed service (semantic spam analysis, priority SMS and managed secure uploads). No external request is made until you explicitly connect this site and enable a capability.', 'relevant-forms' ); ?>
			</p>

			<?php
			$renderNotice(
				[
					'connected'      => [ 'success', __( 'Connected to Relevant Cloud.', 'relevant-forms' ) ],
					'key_saved'      => [ 'success', __( 'Cloud API key saved.', 'relevant-forms' ) ],
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

			<?php $subscription = $connection->subscription(); ?>
			<?php $account = $connection->account(); ?>
			<?php if ( $cloudDisconnected ) : ?>
				<p><?php esc_html_e( 'Connect to Relevant Cloud to subscribe or manage your plan.', 'relevant-forms' ); ?></p>
			<?php else : ?>
				<table class="form-table" role="presentation">
					<?php if ( '' !== $account['email'] || '' !== $account['phone'] ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Account', 'relevant-forms' ); ?></th>
							<td>
								<?php if ( '' !== $account['email'] ) : ?>
									<code><?php echo esc_html( $account['email'] ); ?></code>
								<?php endif; ?>
								<?php if ( '' !== $account['email'] && '' !== $account['phone'] ) : ?>
									&middot;
								<?php endif; ?>
								<?php if ( '' !== $account['phone'] ) : ?>
									<code><?php echo esc_html( $account['phone'] ); ?></code>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'The email and phone number you signed up with on Relevant Cloud.', 'relevant-forms' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Current plan', 'relevant-forms' ); ?></th>
						<td>
							<?php if ( [] === $subscription ) : ?>
								<code><?php esc_html_e( 'unknown — reconnect with a dashboard-issued API key', 'relevant-forms' ); ?></code>
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
							<?php $dashboardUrl = $connection->dashboardUrl(); ?>
							<?php if ( null !== $dashboardUrl && '' !== $dashboardUrl ) : ?>
								<?php if ( '' !== $account['email'] ) : ?>
									<?php $dashboardUrl = add_query_arg( 'email', rawurlencode( $account['email'] ), $dashboardUrl ); ?>
								<?php endif; ?>
								<a href="<?php echo esc_url( $dashboardUrl ); ?>"><?php esc_html_e( 'Manage billing in the Relevant Cloud dashboard', 'relevant-forms' ); ?></a>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'This connection has no Relevant Cloud dashboard link yet. Reconnect with a dashboard-issued API key to manage billing.', 'relevant-forms' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Connection', 'relevant-forms' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection state', 'relevant-forms' ); ?></th>
					<td>
						<p class="relevant-forms-connection-state">
							<code><?php echo esc_html( $cloudState ); ?></code>
							<?php if ( $cloudDisconnected ) : ?>
								<button type="submit" form="rf-cloud-action-form" class="button button-primary"><?php esc_html_e( 'Connect to Cloud', 'relevant-forms' ); ?></button>
							<?php else : ?>
								<button type="submit" form="rf-cloud-action-form" class="button"><?php esc_html_e( 'Disconnect from Cloud', 'relevant-forms' ); ?></button>
							<?php endif; ?>
						</p>
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
				<?php $storedCloudKey = (string) ( $settings['cloud_api_key'] ?? '' ); ?>
				<?php if ( defined( 'RELEVANT_CLOUD_API_KEY' ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cloud API key', 'relevant-forms' ); ?></th>
						<td>
							<input type="password" class="regular-text" id="relevant-cloud-key" value="" disabled autocomplete="new-password">
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
							<input type="password" class="regular-text" id="relevant-cloud-key" name="cloud_api_key" form="rf-cloud-key-form" value="" autocomplete="new-password">
							<p class="description"><?php esc_html_e( 'Paste a new key here to replace the existing one. It saves the moment it is entered; leave blank to keep the current key.', 'relevant-forms' ); ?></p>
						</td>
					</tr>
				<?php else : ?>
					<tr>
						<th scope="row"><label for="relevant-cloud-key"><?php esc_html_e( 'Cloud API key', 'relevant-forms' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="relevant-cloud-key" name="cloud_api_key" form="rf-cloud-key-form" value="" autocomplete="new-password">
							<p class="description"><?php esc_html_e( 'Issued by your Relevant Cloud dashboard. The service endpoint is built in; the key saves the moment it is entered.', 'relevant-forms' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
			</table>
		</div><!-- #rf-panel-cloud -->

		<div id="rf-panel-storage" class="relevant-forms-tabs__panel">
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
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Downloads', 'relevant-forms' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="relevant_forms_settings[storage_block_unscanned_downloads]" value="1" <?php checked( ! empty( $settings['storage_block_unscanned_downloads'] ) ); ?>>
							<?php esc_html_e( 'Block downloads of files that have not been scanned yet', 'relevant-forms' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Basic scanning runs on this site in pure PHP. Deep scanning runs on the Relevant Cloud service for complex analysis and requires an active subscription. The Cloud connection is used automatically once the subscription is active.', 'relevant-forms' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</div><!-- #rf-panel-storage -->

		<div id="rf-panel-payments" class="relevant-forms-tabs__panel">
			<p class="relevant-forms-payments-intro">
				<?php esc_html_e( 'Accept payments on your forms in no time through your own Stripe account (Stripe Connect):', 'relevant-forms' ); ?>
			</p>
			<ul class="description">
				<li><?php esc_html_e( 'Buyers pay you directly on Stripe\'s hosted checkout.', 'relevant-forms' ); ?></li>
				<li><?php esc_html_e( 'Relevant Cloud applies the platform fee below to each charge.', 'relevant-forms' ); ?></li>
				<li><?php esc_html_e( 'Card data never touches this site.', 'relevant-forms' ); ?></li>
			</ul>

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
								esc_html__( 'Platform fee: Relevant Forms collects a %s%% fee on each charge, in addition to Stripe\'s own processing fees. Both are collected automatically by Stripe. Payouts and refunds happen on your own Stripe account.', 'relevant-forms' ),
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
							<div class="relevant-forms-actions">
								<button type="submit" form="rf-stripe-connect-form" class="button button-primary"><?php echo $paymentsConnected ? esc_html__( 'Reconnect Stripe', 'relevant-forms' ) : esc_html__( 'Connect Stripe', 'relevant-forms' ); ?></button>
								<button type="submit" form="rf-stripe-refresh-form" class="button"><?php esc_html_e( 'Refresh status', 'relevant-forms' ); ?></button>
								<?php if ( $paymentsConnected ) : ?>
									<button type="submit" form="rf-stripe-disconnect-form" class="button button-link-delete"><?php esc_html_e( 'Disconnect Stripe', 'relevant-forms' ); ?></button>
								<?php endif; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Connecting opens Stripe: you choose (or create) the account that receives the money. Access can be revoked here or from your Stripe dashboard at any time.', 'relevant-forms' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div><!-- #rf-panel-payments -->
	</form>

	<?php /* Nonced action forms for the panel buttons, declared outside the options form (HTML forbids nested forms); each button targets its form by id. */ ?>
	<form id="rf-cloud-action-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
		<?php if ( ! $cloudDisconnected ) : ?>
			<input type="hidden" name="action" value="relevant_forms_cloud_disconnect">
			<?php wp_nonce_field( 'relevant_cloud_disconnect' ); ?>
		<?php else : ?>
			<input type="hidden" name="action" value="relevant_forms_cloud_connect">
			<?php wp_nonce_field( 'relevant_cloud_connect' ); ?>
		<?php endif; ?>
	</form>

	<?php if ( ! defined( 'RELEVANT_CLOUD_API_KEY' ) ) : ?>
		<?php /* The key field saves on change: its input targets this nonced form. */ ?>
		<form id="rf-cloud-key-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
			<input type="hidden" name="action" value="relevant_forms_cloud_save_key">
			<?php wp_nonce_field( 'relevant_cloud_save_key' ); ?>
		</form>
	<?php endif; ?>

	<?php if ( $paymentsClientOk ) : ?>
		<form id="rf-stripe-connect-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
			<input type="hidden" name="action" value="relevant_forms_payments_connect">
			<?php wp_nonce_field( 'relevant_payments_connect' ); ?>
		</form>

		<form id="rf-stripe-refresh-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
			<input type="hidden" name="action" value="relevant_forms_payments_refresh">
			<?php wp_nonce_field( 'relevant_payments_refresh' ); ?>
		</form>

		<?php if ( $paymentsConnected ) : ?>
			<form id="rf-stripe-disconnect-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
				<input type="hidden" name="action" value="relevant_forms_payments_disconnect">
				<?php wp_nonce_field( 'relevant_payments_disconnect' ); ?>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</div>
