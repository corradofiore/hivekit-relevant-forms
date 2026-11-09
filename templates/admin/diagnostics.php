<?php
/**
 * Diagnostics template.
 *
 * @package RelevantForms
 *
 * @var array<string, string|null> $tables         Table existence checks.
 * @var string                     $dbVersion      Applied schema version.
 * @var array<string, mixed>       $settings       Current settings.
 * @var string                     $serviceUrl     Resolved Cloud service origin.
 * @var string                     $channel        Build channel (production, qa).
 * @var bool                       $isPreRelease   Whether this is a non-production build.
 * @var bool                       $mailConfigured Whether a mailer owns the outbound transport.
 * @var string                     $mailAdvice     What to do when none does.
 * @var string                     $apiAction      API status test AJAX action.
 * @var string                     $apiNonce       Nonce for the API status test.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Diagnostics', 'hivekit-relevant-forms' ); ?></h1>

	<h2><?php esc_html_e( 'Environment', 'hivekit-relevant-forms' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<tr>
				<td><?php esc_html_e( 'WordPress version', 'hivekit-relevant-forms' ); ?></td>
				<td><?php echo esc_html( (string) get_bloginfo( 'version' ) ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'PHP version', 'hivekit-relevant-forms' ); ?></td>
				<td><?php echo esc_html( PHP_VERSION ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Plugin version', 'hivekit-relevant-forms' ); ?></td>
				<td><?php echo esc_html( RELEVANT_FORMS_VERSION ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Database schema version', 'hivekit-relevant-forms' ); ?></td>
				<td><?php echo esc_html( $dbVersion ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Debug logging', 'hivekit-relevant-forms' ); ?></td>
				<td><?php echo empty( $settings['debug_logging'] ) ? esc_html__( 'Off', 'hivekit-relevant-forms' ) : esc_html__( 'On', 'hivekit-relevant-forms' ); ?></td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Service', 'hivekit-relevant-forms' ); ?></h2>
	<?php if ( $isPreRelease ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'This is a pre-release build.', 'hivekit-relevant-forms' ); ?></strong>
				<?php
				printf(
					/* translators: %s: build channel, e.g. qa. */
					esc_html__( 'It was built for the %s channel', 'hivekit-relevant-forms' ),
					esc_html( $channel )
				);
				?>
			</p>
		</div>
	<?php endif; ?>
	<table class="widefat striped">
		<tbody>
			<tr>
				<td><?php esc_html_e( 'Build channel', 'hivekit-relevant-forms' ); ?></td>
				<td>
					<?php if ( $isPreRelease ) : ?>
						<strong><?php echo esc_html( strtoupper( $channel ) ); ?></strong>
						<?php
						printf(
							/* translators: %s: plugin version, e.g. 1.0.0-qa. */
							esc_html__( '— %s (pre-release)', 'hivekit-relevant-forms' ),
							esc_html( RELEVANT_FORMS_VERSION )
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'Production', 'hivekit-relevant-forms' ); ?>
						<?php
						printf(
							/* translators: %s: plugin version, e.g. 1.0.0. */
							esc_html__( '— %s', 'hivekit-relevant-forms' ),
							esc_html( RELEVANT_FORMS_VERSION )
						);
						?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Service endpoint', 'hivekit-relevant-forms' ); ?></td>
				<td>
					<code><?php echo esc_html( $serviceUrl ); ?></code>
					<p class="description"><?php esc_html_e( 'Built into this build. Sites can override it with the RELEVANT_CLOUD_URL constant in wp-config.php.', 'hivekit-relevant-forms' ); ?></p>
				</td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Outbound email', 'hivekit-relevant-forms' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<tr>
				<td><?php esc_html_e( 'Mail transport', 'hivekit-relevant-forms' ); ?></td>
				<td>
					<?php if ( $mailConfigured ) : ?>
						<?php esc_html_e( 'An SMTP or relay mailer is active.', 'hivekit-relevant-forms' ); ?>
					<?php else : ?>
						<strong><?php esc_html_e( 'No SMTP transport detected — mail is handed to PHP mail().', 'hivekit-relevant-forms' ); ?></strong>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>
	<?php if ( ! $mailConfigured ) : ?>
		<div class="notice notice-warning inline">
			<p><?php echo esc_html( $mailAdvice ); ?></p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Database tables', 'hivekit-relevant-forms' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<?php foreach ( $tables as $tableName => $present ) : ?>
				<tr>
					<td><code><?php echo esc_html( $tableName ); ?></code></td>
					<td>
						<?php echo null !== $present ? esc_html__( 'Present', 'hivekit-relevant-forms' ) : '<strong>' . esc_html__( 'Missing', 'hivekit-relevant-forms' ) . '</strong>'; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'API status', 'hivekit-relevant-forms' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Run a live check against the Relevant Cloud health endpoint to verify connectivity from this site.', 'hivekit-relevant-forms' ); ?>
	</p>
	<table class="widefat striped">
		<tbody>
			<tr>
				<td><?php esc_html_e( 'Connection test', 'hivekit-relevant-forms' ); ?></td>
				<td>
					<button type="button" class="button" id="rf-test-api-status" data-action="<?php echo esc_attr( $apiAction ); ?>" data-nonce="<?php echo esc_attr( $apiNonce ); ?>" data-busy="<?php esc_attr_e( 'Testing…', 'hivekit-relevant-forms' ); ?>" data-failed="<?php esc_attr_e( 'The API status test could not be completed.', 'hivekit-relevant-forms' ); ?>"><?php esc_html_e( 'Test API connection', 'hivekit-relevant-forms' ); ?></button>
					<p class="rf-sms-message" id="rf-test-api-status-result" hidden></p>
				</td>
			</tr>
		</tbody>
	</table>
</div>
