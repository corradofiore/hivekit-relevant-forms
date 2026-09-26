<?php
/**
 * WPForms import screen (doc §46–47).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Import from WPForms', 'relevant-forms' ); ?></h1>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status marker set by our own redirect.
	$imported = ! empty( $_GET['imported'] );
	?>
	<?php if ( $imported ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Import finished. Review the report below and publish the imported drafts when ready.', 'relevant-forms' ); ?></p></div>
	<?php endif; ?>

	<?php if ( empty( $scan['installed'] ) ) : ?>
		<p><?php esc_html_e( 'No WPForms forms were found on this site. If WPForms is installed but deactivated, its forms are still detected — nothing is ever modified or deleted.', 'relevant-forms' ); ?></p>
	<?php else : ?>
		<h2><?php esc_html_e( 'Compatibility scan', 'relevant-forms' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: number of forms, 2: estimated compatibility percentage */
				esc_html__( '%1$d forms found — estimated compatibility: %2$d%%.', 'relevant-forms' ),
				(int) $scan['total'],
				(int) $scan['compatibility']
			);
			?>
			<?php if ( '' !== (string) $scan['version'] ) : ?>
				<?php
				printf(
					/* translators: %s: WPForms version */
					esc_html__( 'WPForms version: %s.', 'relevant-forms' ),
					esc_html( (string) $scan['version'] )
				);
				?>
			<?php elseif ( ! $scan['active'] ) : ?>
				<?php esc_html_e( 'WPForms is not active; leftover form records are being scanned read-only.', 'relevant-forms' ); ?>
			<?php endif; ?>
		</p>

		<p>
			<?php
			printf(
				/* translators: 1: fully compatible count, 2: review count, 3: unsupported count */
				esc_html__( 'Fully compatible: %1$d · Requires review: %2$d · Unsupported integrations: %3$d', 'relevant-forms' ),
				(int) $scan['compatible'],
				(int) $scan['review'],
				(int) $scan['unsupported']
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 1em 0;">
			<input type="hidden" name="action" value="relevant_forms_import_wpforms">
			<input type="hidden" name="import_all" value="1">
			<?php wp_nonce_field( 'relevant_import_wpforms' ); ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Import all compatible forms', 'relevant-forms' ); ?></button>
			<p class="description"><?php esc_html_e( 'Forms flagged as unsupported integrations are skipped in bulk import. Everything is imported as a draft.', 'relevant-forms' ); ?></p>
		</form>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Form', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Fields', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Issues', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'relevant-forms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $scan['forms'] as $row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( (string) $row['title'] ); ?></strong></td>
						<td>
							<?php
							printf(
								/* translators: 1: total fields, 2: mapped fields */
								esc_html__( '%1$d (%2$d importable)', 'relevant-forms' ),
								(int) $row['fields'],
								(int) $row['mapped']
							);
							?>
						</td>
						<td><code><?php echo esc_html( (string) $row['status'] ); ?></code></td>
						<td>
							<?php if ( [] === $row['issues'] ) : ?>
								&mdash;
							<?php else : ?>
								<ul style="margin:0;list-style:disc;padding-left:1.2em;">
									<?php foreach ( $row['issues'] as $issue ) : ?>
										<li><?php echo esc_html( (string) $issue ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="relevant_forms_import_wpforms">
								<input type="hidden" name="wpforms_form_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
								<?php wp_nonce_field( 'relevant_import_wpforms' ); ?>
								<button type="submit" class="button" <?php disabled( 0 === (int) $row['mapped'] ); ?>><?php esc_html_e( 'Import', 'relevant-forms' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( ! empty( $report['rows'] ) ) : ?>
		<h2 style="margin-top:2em;"><?php esc_html_e( 'Last migration report', 'relevant-forms' ); ?></h2>
		<?php if ( ! empty( $report['at'] ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: UTC timestamp */
					esc_html__( 'Run at %s (UTC).', 'relevant-forms' ),
					esc_html( (string) $report['at'] )
				);
				?>
			</p>
		<?php endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Form', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'relevant-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'relevant-forms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $report['rows'] as $entry ) : ?>
					<?php
					$reportFormId = (int) ( $entry['form_id'] ?? 0 );

					// Link imported rows to their form editor; rows whose draft
					// no longer exists (purged) stay unlinked.
					$reportEdit = 'imported' === ( $entry['status'] ?? '' )
						&& $reportFormId > 0
						&& null !== ( new \RelevantForms\Forms\FormRepository() )->find( $reportFormId )
						? admin_url( 'admin.php?page=relevant-form-edit&form_id=' . $reportFormId )
						: '';
					?>
					<tr>
						<td>
							<?php if ( '' !== $reportEdit ) : ?>
								<a href="<?php echo esc_url( $reportEdit ); ?>"><strong><?php echo esc_html( (string) ( $entry['title'] ?? '' ) ); ?></strong></a>
							<?php else : ?>
								<strong><?php echo esc_html( (string) ( $entry['title'] ?? '' ) ); ?></strong>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( (string) ( $entry['status'] ?? '' ) ); ?></code></td>
						<td>
							<?php if ( 'imported' === ( $entry['status'] ?? '' ) ) : ?>
								<?php
								$reportDraft = sprintf(
									/* translators: %d: new draft form id. */
									__( 'draft #%d', 'relevant-forms' ),
									$reportFormId
								);

								$reportDraft = '' !== $reportEdit
									? sprintf( '<a href="%s">%s</a>', esc_url( $reportEdit ), esc_html( $reportDraft ) )
									: esc_html( $reportDraft );

								echo wp_kses(
									sprintf(
										/* translators: 1: number of imported fields, 2: the draft name, e.g. "draft #34" */
										__( '%1$d fields imported as %2$s.', 'relevant-forms' ),
										(int) ( $entry['imported_fields'] ?? 0 ),
										$reportDraft
									),
									[
										'a' => [ 'href' => [] ],
									]
								);
								?>
							<?php else : ?>
								<?php echo esc_html( (string) ( $entry['error'] ?? '' ) ); ?>
							<?php endif; ?>
							<?php if ( ! empty( $entry['skipped'] ) ) : ?>
								<ul style="margin:0;list-style:disc;padding-left:1.2em;">
									<?php foreach ( $entry['skipped'] as $skipped ) : ?>
										<li>
											<?php
											printf(
												/* translators: 1: field label, 2: WPForms field type */
												esc_html__( 'Skipped "%1$s" (%2$s).', 'relevant-forms' ),
												esc_html( (string) ( $skipped['label'] ?? '' ) ),
												esc_html( (string) ( $skipped['type'] ?? '' ) )
											);
											?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<?php if ( ! empty( $entry['notes'] ) ) : ?>
								<ul style="margin:0;list-style:disc;padding-left:1.2em;">
									<?php foreach ( $entry['notes'] as $note ) : ?>
										<li><?php echo esc_html( (string) $note ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
