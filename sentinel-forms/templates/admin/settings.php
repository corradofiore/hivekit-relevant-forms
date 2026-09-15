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
			<?php esc_html_e( 'Uploads are stored privately by default. Choose an S3-compatible provider (Hetzner Object Storage, Backblaze B2, AWS S3…) or keep local storage. Constants in wp-config.php override these settings.', 'sentinel-forms' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sentinel-storage-provider"><?php esc_html_e( 'Provider', 'sentinel-forms' ); ?></label></th>
				<td>
					<select id="sentinel-storage-provider" name="sentinel_forms_settings[storage_provider]" <?php disabled( defined( 'SENTINEL_STORAGE_PROVIDER' ) ); ?>>
						<option value="local" <?php selected( (string) ( $settings['storage_provider'] ?? '' ), 'local' ); ?>><?php esc_html_e( 'Local (private server directory)', 'sentinel-forms' ); ?></option>
						<option value="s3" <?php selected( (string) ( $settings['storage_provider'] ?? '' ), 's3' ); ?>><?php esc_html_e( 'S3-compatible object storage', 'sentinel-forms' ); ?></option>
					</select>
					<?php if ( defined( 'SENTINEL_STORAGE_PROVIDER' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'sentinel-forms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-storage-endpoint"><?php esc_html_e( 'Endpoint URL', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="sentinel-storage-endpoint" name="sentinel_forms_settings[storage_endpoint]" value="<?php echo esc_attr( (string) ( $settings['storage_endpoint'] ?? '' ) ); ?>" <?php disabled( defined( 'SENTINEL_STORAGE_ENDPOINT' ) ); ?> placeholder="https://s3.us-west-004.backblazeb2.com">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-storage-region"><?php esc_html_e( 'Region', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="sentinel-storage-region" name="sentinel_forms_settings[storage_region]" value="<?php echo esc_attr( (string) ( $settings['storage_region'] ?? '' ) ); ?>" <?php disabled( defined( 'SENTINEL_STORAGE_REGION' ) ); ?> placeholder="us-west-004">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-storage-bucket"><?php esc_html_e( 'Bucket', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="sentinel-storage-bucket" name="sentinel_forms_settings[storage_bucket]" value="<?php echo esc_attr( (string) ( $settings['storage_bucket'] ?? '' ) ); ?>" <?php disabled( defined( 'SENTINEL_STORAGE_BUCKET' ) ); ?>>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-storage-access-key"><?php esc_html_e( 'Access key', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="sentinel-storage-access-key" name="sentinel_forms_settings[storage_access_key]" value="<?php echo esc_attr( (string) ( $settings['storage_access_key'] ?? '' ) ); ?>" <?php disabled( defined( 'SENTINEL_STORAGE_ACCESS_KEY' ) ); ?>>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sentinel-storage-secret-key"><?php esc_html_e( 'Secret key', 'sentinel-forms' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="sentinel-storage-secret-key" name="sentinel_forms_settings[storage_secret_key]" value="" <?php disabled( defined( 'SENTINEL_STORAGE_SECRET_KEY' ) ); ?> autocomplete="new-password">
					<?php if ( defined( 'SENTINEL_STORAGE_SECRET_KEY' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'sentinel-forms' ); ?></p>
					<?php elseif ( '' !== (string) ( $settings['storage_secret_key'] ?? '' ) ) : ?>
						<p class="description"><?php esc_html_e( 'A secret is stored. Leave blank to keep it.', 'sentinel-forms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Downloads', 'sentinel-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="sentinel_forms_settings[storage_block_unscanned_downloads]" value="1" <?php checked( ! empty( $settings['storage_block_unscanned_downloads'] ) ); ?>>
						<?php esc_html_e( 'Block downloads of files that have not been scanned yet (Phase 3)', 'sentinel-forms' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
