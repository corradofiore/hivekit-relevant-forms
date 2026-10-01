<?php
/**
 * Logs template.
 *
 * @package RelevantForms
 *
 * @var bool               $missing Whether the logs table is missing.
 * @var array<int, object> $rows    Log rows (id, level, message, context, created_at).
 * @var int                $total   Total matching entries.
 * @var int                $paged   Current page number.
 * @var int                $pages   Total pages.
 * @var int                $first   First visible row number.
 * @var int                $last    Last visible row number.
 * @var string             $level   Active level filter ('' = all).
 * @var string             $search  Active message search.
 * @var bool               $debugOn Whether debug logging is enabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.

$levelLabels = [
	'error'   => __( 'Error', 'relevant-forms' ),
	'warning' => __( 'Warning', 'relevant-forms' ),
	'info'    => __( 'Info', 'relevant-forms' ),
	'debug'   => __( 'Debug', 'relevant-forms' ),
];
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Logs', 'relevant-forms' ); ?></h1>

	<?php if ( isset( $_GET['cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Logs cleared.', 'relevant-forms' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['debug_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Debug logging updated.', 'relevant-forms' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $missing ) ) : ?>
		<div class="notice notice-error inline">
			<p><?php esc_html_e( 'The log table has not been created yet. Reload this page; if it persists, deactivate and reactivate the plugin.', 'relevant-forms' ); ?></p>
		</div>
	</div><!-- .wrap -->
		<?php
		return;
	endif;
	?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="relevant_forms_save_debug_logging">
		<?php wp_nonce_field( 'relevant_forms_save_debug_logging' ); ?>
		<p>
			<label>
				<input type="checkbox" name="debug_logging" value="1" <?php checked( $debugOn ); ?>>
				<?php esc_html_e( 'Enable debug logging (records detailed info events)', 'relevant-forms' ); ?>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'Errors and warnings are always recorded. Debug logging additionally stores info and debug entries.', 'relevant-forms' ); ?></p>
		<?php submit_button( __( 'Save preferences', 'relevant-forms' ) ); ?>
	</form>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="relevant-logs">
		<select name="level">
			<option value=""><?php esc_html_e( 'All levels', 'relevant-forms' ); ?></option>
			<?php foreach ( \RelevantForms\Support\Logger::LEVELS as $logLevel ) : ?>
				<option value="<?php echo esc_attr( $logLevel ); ?>" <?php selected( $level, $logLevel ); ?>><?php echo esc_html( $levelLabels[ $logLevel ] ?? $logLevel ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search messages', 'relevant-forms' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'relevant-forms' ); ?></button>
	</form>

	<table class="widefat striped relevant-forms-logs">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Time', 'relevant-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Level', 'relevant-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Message', 'relevant-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Context', 'relevant-forms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( [] === $rows ) : ?>
				<tr>
					<td colspan="4"><?php esc_html_e( 'No log entries found.', 'relevant-forms' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $rows as $logRow ) : ?>
					<?php
					$contextValues = null !== $logRow->context && '' !== (string) $logRow->context
						? json_decode( (string) $logRow->context, true )
						: null;
					?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( (string) $logRow->created_at, 'Y-m-d H:i:s' ) ); ?></td>
						<td><span class="relevant-forms-status relevant-forms-status--<?php echo esc_attr( (string) $logRow->level ); ?>"><?php echo esc_html( (string) $logRow->level ); ?></span></td>
						<td><?php echo esc_html( (string) $logRow->message ); ?></td>
						<td>
							<?php if ( is_array( $contextValues ) ) : ?>
								<details>
									<summary><?php esc_html_e( 'JSON', 'relevant-forms' ); ?></summary>
									<pre><?php echo esc_html( (string) wp_json_encode( $contextValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
								</details>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<div class="tablenav bottom">
		<?php if ( $total > 0 ) : ?>
			<div class="alignleft actions">
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=relevant_forms_clear_logs' ), 'relevant_forms_clear_logs' ) ); ?>" onclick="return confirm( '<?php echo esc_js( __( 'Delete all log entries? This cannot be undone.', 'relevant-forms' ) ); ?>' );"><?php esc_html_e( 'Clear logs', 'relevant-forms' ); ?></a>
			</div>
		<?php endif; ?>
		<div class="tablenav-pages">
			<?php if ( $total > 0 ) : ?>
				<span class="displaying-num">
					<?php
					printf(
						/* translators: 1: first visible row number, 2: last visible row number, 3: total rows. */
						esc_html__( 'Showing %1$d–%2$d of %3$d', 'relevant-forms' ),
						(int) $first,
						(int) $last,
						(int) $total
					);
					?>
				</span>
			<?php endif; ?>
			<?php
			if ( $pages > 1 ) {
				$paginationArgs = [
					'base'      => add_query_arg( 'page', 'relevant-logs', admin_url( 'admin.php' ) ) . '&paged=%#%',
					'format'    => '',
					'current'   => max( 1, (int) $paged ),
					'total'     => (int) $pages,
					'type'      => 'plain',
					'prev_text' => '‹',
					'next_text' => '›',
				];

				$extraArgs = [];

				if ( '' !== $level ) {
					$extraArgs['level'] = $level;
				}

				if ( '' !== $search ) {
					$extraArgs['s'] = $search;
				}

				if ( [] !== $extraArgs ) {
					$paginationArgs['add_args'] = $extraArgs;
				}

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated pagination markup.
				echo paginate_links( $paginationArgs );
			}
			?>
		</div>
	</div>
</div>
