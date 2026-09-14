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
		<?php submit_button(); ?>
	</form>
</div>
