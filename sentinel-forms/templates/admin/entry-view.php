<?php
/**
 * Entry detail template.
 *
 * @package SentinelForms
 *
 * @var SentinelForms\Entries\Entry $entry  Entry being viewed.
 * @var SentinelForms\Forms\Form|null $form  Owning form.
 * @var array<int, object>          $events Domain events.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>
		<?php
		printf(
			/* translators: %d: entry id */
			esc_html__( 'Entry #%d', 'sentinel-forms' ),
			(int) $entry->id
		);
		?>
	</h1>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Entry updated.', 'sentinel-forms' ); ?></p></div>
	<?php endif; ?>

	<p>
		<strong><?php esc_html_e( 'Form:', 'sentinel-forms' ); ?></strong>
		<?php echo esc_html( null !== $form ? $form->name : (string) $entry->formId ); ?>
		&nbsp;|&nbsp;
		<strong><?php esc_html_e( 'Received:', 'sentinel-forms' ); ?></strong>
		<?php echo esc_html( $entry->createdAt ); ?>
	</p>

	<h2><?php esc_html_e( 'Submission', 'sentinel-forms' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Field', 'sentinel-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Value', 'sentinel-forms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $entry->meta as $fieldId => $value ) : ?>
				<tr>
					<td><?php echo esc_html( (string) ( ( $form?->schema->field( $fieldId ) ?? [] )['label'] ?? $fieldId ) ); ?></td>
					<td>
						<?php
						if ( is_array( $value ) ) {
							echo esc_html( implode( ', ', array_map( 'strval', $value ) ) );
						} else {
							echo nl2br( esc_html( (string) $value ) );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<td><?php esc_html_e( 'Source URL', 'sentinel-forms' ); ?></td>
				<td><?php echo esc_html( $entry->sourceUrl ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'IP hash', 'sentinel-forms' ); ?></td>
				<td><code><?php echo esc_html( $entry->ipHash ); ?></code></td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Status', 'sentinel-forms' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="sentinel_forms_entry_status">
		<input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) $entry->id ); ?>">
		<?php wp_nonce_field( 'sentinel_entry_status_' . $entry->id ); ?>
		<select name="entry_status">
			<?php foreach ( \SentinelForms\Entries\Entry::STATUSES as $entryStatus ) : ?>
				<option value="<?php echo esc_attr( $entryStatus ); ?>" <?php selected( $entry->status, $entryStatus ); ?>><?php echo esc_html( ucfirst( $entryStatus ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Update status', 'sentinel-forms' ); ?></button>
	</form>

	<h2><?php esc_html_e( 'History', 'sentinel-forms' ); ?></h2>
	<?php if ( $events === [] ) : ?>
		<p><?php esc_html_e( 'No events recorded.', 'sentinel-forms' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'sentinel-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event', 'sentinel-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'sentinel-forms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $events as $event ) : ?>
					<tr>
						<td><?php echo esc_html( $event->created_at ); ?></td>
						<td><code><?php echo esc_html( $event->event ); ?></code></td>
						<td><code><?php echo esc_html( (string) $event->context ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
