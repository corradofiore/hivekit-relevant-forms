<?php
/**
 * Forms list template.
 *
 * @package SentinelForms
 *
 * @var array<int, SentinelForms\Forms\Form> $forms Forms to list.
 * @var array<int, array<int, array{id: int, title: string, status: string, status_label: string, edit_url: string, view_url: string}>> $usage Form id → posts embedding the shortcode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Forms', 'sentinel-forms' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sentinel-form-new' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Add New Form', 'sentinel-forms' ); ?>
	</a>
	<hr class="wp-header-end">

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Form updated.', 'sentinel-forms' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $forms === [] ) : ?>
		<div class="sentinel-forms-empty">
			<p><?php esc_html_e( 'No forms yet. Create your first form to start collecting submissions.', 'sentinel-forms' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=sentinel-form-new' ) ); ?>">
				<?php esc_html_e( 'Create a form', 'sentinel-forms' ); ?>
			</a>
		</div>
	<?php else : ?>
		<table class="widefat striped sentinel-forms-list">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'sentinel-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'sentinel-forms' ); ?></th>
					<th scope="col" class="sentinel-forms-used-on">
						<?php
						$usageHint = __( 'Only forms embedded with the [sentinel_form] shortcode in post content are detected — pages, posts and other post types. Forms rendered from theme templates with sentinel_forms_render() are not listed.', 'sentinel-forms' );
						?>
						<?php esc_html_e( 'Used on', 'sentinel-forms' ); ?>
						<span class="dashicons dashicons-editor-help sentinel-forms-used-on__hint" title="<?php echo esc_attr( $usageHint ); ?>" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php echo esc_html( $usageHint ); ?></span>
					</th>
					<th scope="col"><?php esc_html_e( 'Shortcode', 'sentinel-forms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Updated', 'sentinel-forms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $forms as $form ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $form->name ); ?></strong>
							<div class="row-actions">
								<span class="edit">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=sentinel-form-new&form_id=' . $form->id ) ); ?>"><?php esc_html_e( 'Edit', 'sentinel-forms' ); ?></a> |
								</span>
								<span class="duplicate">
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sentinel_forms_action&form_action=duplicate&form_id=' . $form->id ), 'sentinel_form_action_duplicate_' . $form->id ) ); ?>"><?php esc_html_e( 'Duplicate', 'sentinel-forms' ); ?></a>
									<?php
									if ( 'archived' !== $form->status ) :
										?>
										|<?php endif; ?>
								</span>
								<?php if ( 'archived' !== $form->status ) : ?>
									<span class="archive">
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sentinel_forms_action&form_action=archive&form_id=' . $form->id ), 'sentinel_form_action_archive_' . $form->id ) ); ?>"><?php esc_html_e( 'Archive', 'sentinel-forms' ); ?></a>
									</span>
								<?php endif; ?>
							</div>
						</td>
						<td>
							<span class="sentinel-forms-status sentinel-forms-status--<?php echo esc_attr( $form->status ); ?>">
								<?php echo esc_html( $form->status ); ?>
							</span>
						</td>
						<td class="sentinel-forms-used-on">
							<?php $usagePages = $usage[ (int) $form->id ] ?? []; ?>
							<?php if ( [] === $usagePages ) : ?>
								<span class="sentinel-forms-used-on__empty">&mdash;</span>
								<span class="screen-reader-text"><?php esc_html_e( 'This form is not embedded anywhere yet.', 'sentinel-forms' ); ?></span>
							<?php else : ?>
								<?php $usageFirst = $usagePages[0]; ?>
								<?php if ( '' !== $usageFirst['edit_url'] ) : ?>
									<a href="<?php echo esc_url( $usageFirst['edit_url'] ); ?>"><?php echo esc_html( $usageFirst['title'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $usageFirst['title'] ); ?>
								<?php endif; ?>
								<?php if ( count( $usagePages ) > 1 ) : ?>
									<span class="sentinel-forms-used-on__more">
										<?php
										printf(
											/* translators: %d: number of additional pages. */
											esc_html__( '+%d more', 'sentinel-forms' ),
											count( $usagePages ) - 1
										);
										?>
									</span>
									<button type="button" class="button-link">
										<?php esc_html_e( 'View all', 'sentinel-forms' ); ?>
									</button>
									<div id="sentinel-forms-usage-<?php echo esc_attr( (string) $form->id ); ?>" class="card sentinel-forms-used-on__popover" hidden>
										<ul>
											<?php foreach ( $usagePages as $usagePage ) : ?>
												<li>
													<?php if ( '' !== $usagePage['edit_url'] ) : ?>
														<a href="<?php echo esc_url( $usagePage['edit_url'] ); ?>"><?php echo esc_html( $usagePage['title'] ); ?></a>
													<?php else : ?>
														<?php echo esc_html( $usagePage['title'] ); ?>
													<?php endif; ?>
													<?php if ( '' !== $usagePage['status_label'] ) : ?>
														<span class="sentinel-forms-used-on__status"><?php echo esc_html( $usagePage['status_label'] ); ?></span>
													<?php endif; ?>
													<?php if ( '' !== $usagePage['view_url'] ) : ?>
																<a href="<?php echo esc_url( $usagePage['view_url'] ); ?>"><?php esc_html_e( 'View', 'sentinel-forms' ); ?></a>
													<?php endif; ?>
												</li>
											<?php endforeach; ?>
										</ul>
									</div>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td>
							<code>[sentinel_form id="<?php echo esc_html( (string) $form->id ); ?>"]</code>
						</td>
						<td><?php echo esc_html( $form->updatedAt ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
