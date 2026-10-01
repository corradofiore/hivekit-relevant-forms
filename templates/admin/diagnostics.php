<?php
/**
 * Diagnostics template.
 *
 * @package RelevantForms
 *
 * @var array<string, string|null> $tables    Table existence checks.
 * @var string                     $dbVersion Applied schema version.
 * @var array<string, mixed>       $settings  Current settings.
 * @var string                     $apiAction API status test AJAX action.
 * @var string                     $apiNonce  Nonce for the API status test.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Diagnostics', 'relevant-forms' ); ?></h1>

	<h2><?php esc_html_e( 'Environment', 'relevant-forms' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<tr>
				<td><?php esc_html_e( 'WordPress version', 'relevant-forms' ); ?></td>
				<td><?php echo esc_html( (string) get_bloginfo( 'version' ) ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'PHP version', 'relevant-forms' ); ?></td>
				<td><?php echo esc_html( PHP_VERSION ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Plugin version', 'relevant-forms' ); ?></td>
				<td><?php echo esc_html( RELEVANT_FORMS_VERSION ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Database schema version', 'relevant-forms' ); ?></td>
				<td><?php echo esc_html( $dbVersion ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Debug logging', 'relevant-forms' ); ?></td>
				<td><?php echo empty( $settings['debug_logging'] ) ? esc_html__( 'Off', 'relevant-forms' ) : esc_html__( 'On', 'relevant-forms' ); ?></td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Database tables', 'relevant-forms' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<?php foreach ( $tables as $tableName => $present ) : ?>
				<tr>
					<td><code><?php echo esc_html( $tableName ); ?></code></td>
					<td>
						<?php echo null !== $present ? esc_html__( 'Present', 'relevant-forms' ) : '<strong>' . esc_html__( 'Missing', 'relevant-forms' ) . '</strong>'; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'API status', 'relevant-forms' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Run a live check against the Relevant Cloud health endpoint to verify connectivity from this site.', 'relevant-forms' ); ?>
	</p>
	<table class="widefat striped">
		<tbody>
			<tr>
				<td><?php esc_html_e( 'Connection test', 'relevant-forms' ); ?></td>
				<td>
					<button type="button" class="button" id="rf-test-api-status" data-action="<?php echo esc_attr( $apiAction ); ?>" data-nonce="<?php echo esc_attr( $apiNonce ); ?>" data-busy="<?php esc_attr_e( 'Testing…', 'relevant-forms' ); ?>" data-failed="<?php esc_attr_e( 'The API status test could not be completed.', 'relevant-forms' ); ?>"><?php esc_html_e( 'Test API connection', 'relevant-forms' ); ?></button>
					<p class="rf-sms-message" id="rf-test-api-status-result" hidden></p>
				</td>
			</tr>
		</tbody>
	</table>
</div>
