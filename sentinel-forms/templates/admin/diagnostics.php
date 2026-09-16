<?php
/**
 * Diagnostics template.
 *
 * @package SentinelForms
 *
 * @var array<string, string|null> $tables    Table existence checks.
 * @var string                     $dbVersion Applied schema version.
 * @var array<string, mixed>       $settings  Current settings.
 * @var array<string, string>      $providers Provider statuses.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Diagnostics', 'sentinel-forms' ); ?></h1>

	<h2><?php esc_html_e( 'Environment', 'sentinel-forms' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<tr>
				<td><?php esc_html_e( 'WordPress version', 'sentinel-forms' ); ?></td>
				<td><?php echo esc_html( (string) get_bloginfo( 'version' ) ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'PHP version', 'sentinel-forms' ); ?></td>
				<td><?php echo esc_html( PHP_VERSION ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Plugin version', 'sentinel-forms' ); ?></td>
				<td><?php echo esc_html( SENTINEL_FORMS_VERSION ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Database schema version', 'sentinel-forms' ); ?></td>
				<td><?php echo esc_html( $dbVersion ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Debug logging', 'sentinel-forms' ); ?></td>
				<td><?php echo empty( $settings['debug_logging'] ) ? esc_html__( 'Off', 'sentinel-forms' ) : esc_html__( 'On', 'sentinel-forms' ); ?></td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Database tables', 'sentinel-forms' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<?php foreach ( $tables as $tableName => $present ) : ?>
				<tr>
					<td><code><?php echo esc_html( $tableName ); ?></code></td>
					<td>
						<?php echo null !== $present ? esc_html__( 'Present', 'sentinel-forms' ) : '<strong>' . esc_html__( 'Missing', 'sentinel-forms' ) . '</strong>'; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Providers', 'sentinel-forms' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<?php foreach ( $providers as $providerName => $providerStatus ) : ?>
				<tr>
					<td><?php echo esc_html( $providerName ); ?></td>
					<td><?php echo esc_html( $providerStatus ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
