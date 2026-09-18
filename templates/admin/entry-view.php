<?php
/**
 * Entry detail template.
 *
 * @package RelevantForms
 *
 * @var RelevantForms\Entries\Entry $entry  Entry being viewed.
 * @var RelevantForms\Forms\Form|null $form  Owning form.
 * @var array<int, object>          $events Domain events.
 * @var array<int, RelevantForms\Uploads\Domain\Upload> $uploads Attached uploads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.
?>
<div class="wrap">
	<h1>
		<?php
		printf(
			/* translators: %d: entry id */
			esc_html__( 'Entry #%d', 'relevant-forms' ),
			(int) $entry->id
		);
		?>
	</h1>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Entry updated.', 'relevant-forms' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['rescanned'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'A new scan has been queued for this file.', 'relevant-forms' ); ?></p></div>
	<?php endif; ?>

	<p>
		<strong><?php esc_html_e( 'Form:', 'relevant-forms' ); ?></strong>
		<?php echo esc_html( null !== $form ? $form->name : (string) $entry->formId ); ?>
		&nbsp;|&nbsp;
		<strong><?php esc_html_e( 'Received:', 'relevant-forms' ); ?></strong>
		<?php echo esc_html( $entry->createdAt ); ?>
	</p>

	<h2><?php esc_html_e( 'Submission', 'relevant-forms' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Field', 'relevant-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Value', 'relevant-forms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $entry->meta as $fieldId => $value ) : ?>
				<?php
				if ( str_starts_with( (string) $fieldId, '_' ) ) {
					continue; }
				?>
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
				<td><?php esc_html_e( 'Source URL', 'relevant-forms' ); ?></td>
				<td><?php echo esc_html( $entry->sourceUrl ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'IP hash', 'relevant-forms' ); ?></td>
				<td><code><?php echo esc_html( $entry->ipHash ); ?></code></td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Spam signals', 'relevant-forms' ); ?></h2>
	<?php
	$signals = is_array( $entry->meta['_spam_signals'] ?? null ) ? $entry->meta['_spam_signals'] : [];
	?>
	<?php if ( [] === $signals ) : ?>
		<p><?php esc_html_e( 'No anti-spam signals recorded for this entry.', 'relevant-forms' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Spam score', 'relevant-forms' ); ?></th><td><code><?php echo esc_html( (string) ( $signals['spam_score'] ?? '0' ) ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Submission time', 'relevant-forms' ); ?></th><td><?php echo esc_html( (string) ( $signals['submission_time_ms'] ?? '0' ) . ' ms' ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Links detected', 'relevant-forms' ); ?></th><td><?php echo esc_html( (string) ( $signals['links_detected'] ?? '0' ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Duplicate count (10 min)', 'relevant-forms' ); ?></th><td><?php echo esc_html( (string) ( $signals['duplicate_count'] ?? '0' ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Recent submissions from this IP hash', 'relevant-forms' ); ?></th><td><?php echo esc_html( (string) ( $signals['recent_submissions'] ?? '0' ) . ' / ' . (string) ( $signals['rate_limit'] ?? '5' ) ); ?></td></tr>
			</tbody>
		</table>
	<?php endif; ?>

	<?php
	$classification = is_array( $entry->meta['_classification'] ?? null ) ? $entry->meta['_classification'] : [];
	$correction     = is_array( $entry->meta['_classification_correction'] ?? null ) ? $entry->meta['_classification_correction'] : [];
	?>
	<h2><?php esc_html_e( 'AI classification', 'relevant-forms' ); ?></h2>
	<?php if ( [] === $classification ) : ?>
		<p><?php esc_html_e( 'No classification recorded for this entry.', 'relevant-forms' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Spam probability', 'relevant-forms' ); ?></th><td><code><?php echo esc_html( (string) ( $classification['spam_probability'] ?? '0' ) ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Relevance', 'relevant-forms' ); ?></th><td><code><?php echo esc_html( (string) ( $classification['relevance'] ?? '0' ) ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Urgency', 'relevant-forms' ); ?></th><td><code><?php echo esc_html( (string) ( $classification['urgency'] ?? '0' ) ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Attention score', 'relevant-forms' ); ?></th><td><code><?php echo esc_html( (string) ( $classification['attention_score'] ?? '0' ) ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Reason code', 'relevant-forms' ); ?></th><td><code><?php echo esc_html( (string) ( $classification['reason_code'] ?? 'unknown' ) ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Summary', 'relevant-forms' ); ?></th><td><?php echo esc_html( (string) ( $classification['summary'] ?? '' ) ); ?></td></tr>
			</tbody>
		</table>
		<?php if ( [] !== $correction ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: administrator verdict, 2: correction timestamp */
					esc_html__( 'Corrected as %1$s on %2$s.', 'relevant-forms' ),
					esc_html( str_replace( '_', ' ', (string) ( $correction['verdict'] ?? '' ) ) ),
					esc_html( (string) ( $correction['at'] ?? '' ) )
				);
				?>
			</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="relevant_forms_correct_classification">
			<input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) $entry->id ); ?>">
			<?php wp_nonce_field( 'relevant_entry_correction_' . $entry->id ); ?>
			<button type="submit" class="button" name="verdict" value="legitimate"><?php esc_html_e( 'Mark legitimate', 'relevant-forms' ); ?></button>
			<button type="submit" class="button" name="verdict" value="spam"><?php esc_html_e( 'Mark spam', 'relevant-forms' ); ?></button>
			<button type="submit" class="button" name="verdict" value="priority"><?php esc_html_e( 'Mark priority', 'relevant-forms' ); ?></button>
			<button type="submit" class="button" name="verdict" value="not_important"><?php esc_html_e( 'Not important', 'relevant-forms' ); ?></button>
			<p class="description"><?php esc_html_e( 'Corrections adjust the entry status and improve the site preference profile used for future classifications.', 'relevant-forms' ); ?></p>
		</form>
		<?php
		$sms_alert = is_array( $entry->meta['_sms_alert'] ?? null ) ? $entry->meta['_sms_alert'] : [];
		?>
		<?php if ( [] !== $sms_alert ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: SMS delivery status, 2: timestamp */
					esc_html__( 'Priority SMS: %1$s (%2$s).', 'relevant-forms' ),
					esc_html( (string) ( $sms_alert['status'] ?? 'unknown' ) ),
					esc_html( (string) ( $sms_alert['at'] ?? '' ) )
				);
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Status', 'relevant-forms' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="relevant_forms_entry_status">
		<input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) $entry->id ); ?>">
		<?php wp_nonce_field( 'relevant_entry_status_' . $entry->id ); ?>
		<select name="entry_status">
			<?php foreach ( \RelevantForms\Entries\Entry::STATUSES as $entryStatus ) : ?>
				<option value="<?php echo esc_attr( $entryStatus ); ?>" <?php selected( $entry->status, $entryStatus ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $entryStatus ) ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Update status', 'relevant-forms' ); ?></button>
	</form>

	<h2><?php esc_html_e( 'Uploads', 'relevant-forms' ); ?></h2>
	<?php if ( [] === $uploads ) : ?>
		<p><?php esc_html_e( 'No uploads attached to this entry.', 'relevant-forms' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'File', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Size', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Scan', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'relevant-forms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $uploads as $upload ) : ?>
					<tr>
						<td><?php echo esc_html( $upload->originalFilename ); ?></td>
						<td><code><?php echo esc_html( '' !== $upload->detectedMime ? $upload->detectedMime : $upload->declaredMime ); ?></code></td>
						<td><?php echo esc_html( size_format( (int) $upload->sizeBytes ) ); ?></td>
						<td><span class="rf-badge rf-badge--<?php echo esc_attr( $upload->status ); ?>"><?php echo esc_html( strtoupper( $upload->status ) ); ?></span></td>
						<td>
							<?php
							switch ( $upload->scanStatus ) {
								case 'clean':
									esc_html_e( 'Clean', 'relevant-forms' );
									break;
								case 'infected':
									echo '<strong style="color:#b32d2e;">' . esc_html__( 'Malware detected', 'relevant-forms' ) . '</strong>';
									break;
								case 'scanning':
									esc_html_e( 'Scanning…', 'relevant-forms' );
									break;
								case 'suspicious':
									esc_html_e( 'Suspicious — quarantined', 'relevant-forms' );
									break;
								case 'error':
									esc_html_e( 'Scan error — quarantined', 'relevant-forms' );
									break;
								case 'skipped':
									esc_html_e( 'Not required', 'relevant-forms' );
									break;
								default:
									esc_html_e( 'Pending scan', 'relevant-forms' );
							}

							if ( '' !== $upload->scanReference ) {
								echo '<br><code>' . esc_html( $upload->scanReference ) . '</code>';
							}
							?>
						</td>
						<td>
							<?php if ( \RelevantForms\Uploads\Domain\UploadStatus::isDownloadable( $upload->status, \RelevantForms\Uploads\Storage\StorageConfig::allowUnscannedDownload() ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=relevant_forms_download_upload&upload_id=' . (int) $upload->id ), 'relevant_download_' . (int) $upload->id ) ); ?>"><?php esc_html_e( 'Download', 'relevant-forms' ); ?></a>
							<?php else : ?>
								<span aria-disabled="true" class="button button-small disabled"><?php esc_html_e( 'Blocked', 'relevant-forms' ); ?></span>
							<?php endif; ?>
							<?php if ( in_array( $upload->status, [ 'quarantined', 'infected', 'clean', 'scanning' ], true ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=relevant_forms_rescan_upload&upload_id=' . (int) $upload->id ), 'relevant_rescan_' . (int) $upload->id ) ); ?>"><?php esc_html_e( 'Rescan', 'relevant-forms' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'History', 'relevant-forms' ); ?></h2>
	<?php if ( $events === [] ) : ?>
		<p><?php esc_html_e( 'No events recorded.', 'relevant-forms' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'relevant-forms' ); ?></th>
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
