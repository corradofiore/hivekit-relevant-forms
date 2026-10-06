<?php
/**
 * Settings template: Cloud subscription and file storage sections under
 * two tabs sharing one options form.
 *
 * The nonced action buttons inside the panels (connect/disconnect) submit
 * forms declared after the options form: HTML forbids nested forms, so
 * each button is associated with its action form via the form attribute
 * instead.
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
	<h1><?php esc_html_e( 'Relevant Cloud', 'relevant-forms' ); ?></h1>
	<?php settings_errors(); ?>

	<?php
	$connection        = new CloudConnection();
	$cloudState        = $connection->state();
	$cloudDisconnected = CloudConnection::STATE_DISCONNECTED === $cloudState;

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status marker set by our own redirect.
	$cloudNotice = isset( $_GET['cloud'] ) ? sanitize_key( wp_unslash( $_GET['cloud'] ) ) : '';

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

	<p class="relevant-forms-cloud-intro">
		<?php esc_html_e( 'Relevant Cloud is an optional paid managed service adding semantic spam analysis, priority SMS alerting, managed secure uploads, and Stripe payments to your forms. No external request is made until you explicitly connect this site and enable a capability.', 'relevant-forms' ); ?>
	</p>

	<form method="post" action="options.php">
		<?php settings_fields( 'relevant_forms_settings' ); ?>

		<nav class="nav-tab-wrapper relevant-forms-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'relevant-forms' ); ?>">
			<a class="nav-tab nav-tab-active relevant-forms-tabs__tab" href="<?php echo esc_url( '#rf-panel-cloud' ); ?>"><?php esc_html_e( 'Relevant Cloud subscription', 'relevant-forms' ); ?></a>
			<a class="nav-tab relevant-forms-tabs__tab" href="<?php echo esc_url( '#rf-panel-storage' ); ?>"><?php esc_html_e( 'File storage', 'relevant-forms' ); ?></a>
		</nav>

		<div id="rf-panel-cloud" class="relevant-forms-tabs__panel">
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
							<?php elseif ( $connection->isComplimentary() ) : ?>
								<code><?php esc_html_e( 'Free (complimentary)', 'relevant-forms' ); ?></code>
								<?php if ( null !== $connection->complimentaryUntil() ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %s: complimentary access end date */
											esc_html__( 'Complimentary access until %s.', 'relevant-forms' ),
											esc_html( (string) $connection->complimentaryUntil() )
										);
										?>
									</p>
								<?php endif; ?>
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
							<?php $discountLabel = $connection->discountLabel(); ?>
							<?php if ( null !== $discountLabel ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: discount label, e.g. "20% off" */
										esc_html__( 'Discount applied: %s.', 'relevant-forms' ),
										esc_html( $discountLabel )
									);
									?>
								</p>
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
</div>
