<?php
/**
 * Accept payments admin page (formerly the "Payments configuration
 * (Stripe)" tab of the settings screen).
 *
 * The nonced action buttons submit hidden forms declared after the page
 * content via the form attribute, matching the settings screen pattern.
 *
 * @package RelevantForms
 *
 * @var array<string, mixed> $paymentsStatus    Connection payment snapshot.
 * @var bool                 $paymentsConnected Whether Stripe is connected.
 * @var bool                 $paymentsClientOk  Whether the Cloud connection can broker payments.
 * @var string               $feeLabel          Formatted platform fee percentage.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Accept payments', 'relevant-forms' ); ?></h1>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status marker set by our own redirect.
	$paymentsNotice = isset( $_GET['payments'] ) ? sanitize_key( wp_unslash( $_GET['payments'] ) ) : '';

	$paymentsNotices = [
		'connected'     => [ 'success', __( 'Stripe account connected. Payments are ready on every form with payment fields.', 'relevant-forms' ) ],
		'disconnected'  => [ 'info', __( 'Stripe account disconnected. Existing entries keep their payment records.', 'relevant-forms' ) ],
		'error'         => [ 'error', __( 'The Stripe authorization was not completed. No changes were made.', 'relevant-forms' ) ],
		'unavailable'   => [ 'error', __( 'Payments are not available on this deployment yet. Check the Cloud connection and try again.', 'relevant-forms' ) ],
		'not_connected' => [ 'error', __( 'Connect this site to Relevant Cloud first, then connect Stripe here.', 'relevant-forms' ) ],
	];

	if ( isset( $paymentsNotices[ $paymentsNotice ] ) ) {
		echo '<div class="notice notice-' . esc_attr( $paymentsNotices[ $paymentsNotice ][0] ) . ' is-dismissible"><p>' . esc_html( $paymentsNotices[ $paymentsNotice ][1] ) . '</p></div>';
	}
	?>

	<p class="relevant-forms-payments-intro">
		<?php esc_html_e( 'Accept payments on your forms in no time through your own Stripe account (Stripe Connect):', 'relevant-forms' ); ?>
	</p>
	<ul class="description">
		<li><?php esc_html_e( 'Buyers pay you directly on Stripe\'s hosted checkout.', 'relevant-forms' ); ?></li>
		<li><?php esc_html_e( 'Relevant Cloud applies the platform fee below to each charge.', 'relevant-forms' ); ?></li>
		<li><?php esc_html_e( 'Card data never touches this site.', 'relevant-forms' ); ?></li>
	</ul>

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
					<p class="description">
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: link to the Relevant Cloud settings screen. */
								__( 'Connect this site to %s first, then connect Stripe here.', 'relevant-forms' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=relevant-settings' ) ) . '">' . esc_html__( 'Relevant Cloud settings', 'relevant-forms' ) . '</a>'
							)
						);
						?>
					</p>
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
</div>

<?php if ( $paymentsClientOk ) : ?>
	<?php /* Nonced action forms for the buttons above; each button targets its form by id. */ ?>
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
