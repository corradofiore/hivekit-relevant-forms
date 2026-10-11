<?php
/**
 * Entries list template.
 *
 * @package RelevantForms
 *
 * @var array<int, RelevantForms\Entries\Entry> $entries Entries to list.
 * @var array<string, int>                      $counts  Per-status counts.
 * @var string                                  $status  Active status filter (may be empty).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Entries', 'relevant-forms' ); ?></h1>

	<ul class="subsubsub">
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=relevant-entries' ) ); ?>" class="<?php echo '' === $status ? 'current' : ''; ?>">
				<?php esc_html_e( 'All', 'relevant-forms' ); ?> <span class="count">(<?php echo esc_html( (string) $counts['total'] ); ?>)</span>
			</a>
		</li>
		<?php foreach ( \RelevantForms\Entries\Entry::STATUSES as $entryStatus ) : ?>
			<li>|
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=relevant-entries&status=' . $entryStatus ) ); ?>" class="<?php echo $status === $entryStatus ? 'current' : ''; ?>">
					<?php echo esc_html( ucfirst( $entryStatus ) ); ?> <span class="count">(<?php echo esc_html( (string) $counts[ $entryStatus ] ); ?>)</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( $entries === [] ) : ?>
		<div class="relevant-forms-empty">
			<p><?php esc_html_e( 'No entries yet.', 'relevant-forms' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Form', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'SMS summary', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Preview', 'relevant-forms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php
					$form                 = ( new \RelevantForms\Forms\FormRepository() )->find( $entry->formId );
					$entry_preview        = '';
					$entry_classification = is_array( $entry->meta['_classification'] ?? null ) ? $entry->meta['_classification'] : [];

					/*
					 * The short summary the SMS alert sends, falling back to the email
					 * phrase for entries classified before the Cloud returned it — the
					 * same rule `Classification::fromArray()` applies.
					 */
					$entry_sms_summary = trim(
						(string) ( $entry_classification['sms_summary'] ?? ( $entry_classification['summary'] ?? '' ) )
					);

					foreach ( $entry->meta as $value ) {
						if ( is_string( $value ) && trim( $value ) !== '' ) {
							$entry_preview = wp_trim_words( $value, 12 );
							break;
						}
					}
					?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=relevant-entry&entry_id=' . $entry->id ) ); ?>"><?php echo esc_html( $entry->createdAt ); ?></a></td>
						<td><?php echo esc_html( null !== $form ? $form->name : (string) $entry->formId ); ?></td>
						<td>
							<span class="relevant-forms-status relevant-forms-status--<?php echo esc_attr( $entry->status ); ?>">
								<?php echo esc_html( ucwords( str_replace( '_', ' ', $entry->status ) ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( '' !== $entry_sms_summary ? $entry_sms_summary : '—' ); ?></td>
						<td><?php echo esc_html( $entry_preview ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
