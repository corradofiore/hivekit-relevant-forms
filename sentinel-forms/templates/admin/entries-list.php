<?php
/**
 * Entries list template.
 *
 * @package SentinelForms
 *
 * @var array<int, SentinelForms\Entries\Entry> $entries Entries to list.
 * @var array<string, int>                      $counts  Per-status counts.
 * @var string                                  $status  Active status filter (may be empty).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Entries', 'sentinel-forms' ); ?></h1>

	<ul class="subsubsub">
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=sentinel-entries' ) ); ?>" class="<?php echo '' === $status ? 'current' : ''; ?>">
				<?php esc_html_e( 'All', 'sentinel-forms' ); ?> <span class="count">(<?php echo esc_html( (string) $counts['total'] ); ?>)</span>
			</a>
		</li>
		<?php foreach ( \SentinelForms\Entries\Entry::STATUSES as $entryStatus ) : ?>
			<li>|
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sentinel-entries&status=' . $entryStatus ) ); ?>" class="<?php echo $status === $entryStatus ? 'current' : ''; ?>">
					<?php echo esc_html( ucfirst( $entryStatus ) ); ?> <span class="count">(<?php echo esc_html( (string) $counts[ $entryStatus ] ); ?>)</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( $entries === [] ) : ?>
		<div class="sentinel-forms-empty">
			<p><?php esc_html_e( 'No entries yet.', 'sentinel-forms' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'sentinel-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Form', 'sentinel-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'sentinel-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Preview', 'sentinel-forms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php
					$form          = ( new \SentinelForms\Forms\FormRepository() )->find( $entry->formId );
					$entry_preview = '';
					foreach ( $entry->meta as $value ) {
						if ( is_string( $value ) && trim( $value ) !== '' ) {
							$entry_preview = wp_trim_words( $value, 12 );
							break;
						}
					}
					?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=sentinel-entry&entry_id=' . $entry->id ) ); ?>"><?php echo esc_html( $entry->createdAt ); ?></a></td>
						<td><?php echo esc_html( null !== $form ? $form->name : (string) $entry->formId ); ?></td>
						<td>
							<span class="sentinel-forms-status sentinel-forms-status--<?php echo esc_attr( $entry->status ); ?>">
								<?php echo esc_html( $entry->status ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $entry_preview ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
