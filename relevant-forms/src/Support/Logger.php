<?php
/**
 * Structured logger writing to the relevant_logs table.
 *
 * Errors and warnings are always recorded. Info/debug entries require the
 * opt-in "debug logging" setting.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Support;

/**
 * PSR-style levelled logger persisted in a custom table.
 */
final class Logger {

	/**
	 * Allowed log levels.
	 *
	 * @var array<int, string>
	 */
	public const LEVELS = [ 'error', 'warning', 'info', 'debug' ];

	/**
	 * Default cap for persisted entries; older rows are pruned on write.
	 */
	public const MAX_ROWS = 2000;

	/**
	 * Settings option key.
	 */
	private const OPTION = 'relevant_forms_settings';

	/**
	 * Log an error.
	 *
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public static function error( string $message, array $context = [] ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Log a warning.
	 *
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public static function warning( string $message, array $context = [] ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Log an informational entry (requires debug logging).
	 *
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public static function info( string $message, array $context = [] ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Log a debug entry (requires debug logging).
	 *
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public static function debug( string $message, array $context = [] ): void {
		self::log( 'debug', $message, $context );
	}

	/**
	 * Persist a log entry.
	 *
	 * @param string               $level   One of error|warning|info|debug.
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public static function log( string $level, string $message, array $context = [] ): void {
		global $wpdb;

		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'info';
		}

		if ( ! self::shouldLog( $level ) ) {
			return;
		}

		do_action( 'relevant_forms_log', $level, $message, $context );

		try {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'relevant_logs',
				[
					'level'      => $level,
					'message'    => $message,
					'context'    => $context !== [] ? wp_json_encode( $context ) : null,
					'created_at' => current_time( 'mysql', true ),
				]
			);

			self::prune();
		} catch ( \Throwable $exception ) {
			// Last-resort fallback: never let logging break the request.
			error_log( sprintf( '[relevant-forms] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Whether a level passes the current logging policy.
	 */
	private static function shouldLog( string $level ): bool {
		if ( 'error' === $level || 'warning' === $level ) {
			return true;
		}

		$settings = (array) get_option( self::OPTION, [] );

		return ! empty( $settings['debug_logging'] );
	}

	/**
	 * Trim the log table to the configured cap, oldest entries first.
	 *
	 * Runs after every persisted entry — the table never exceeds the cap by
	 * more than one row, so the extra DELETE stays cheap. The filter can
	 * raise or lower the cap; 0 (or lower) disables trimming.
	 */
	private static function prune(): void {
		global $wpdb;

		$max = (int) apply_filters( 'relevant_forms_log_max_rows', self::MAX_ROWS );

		if ( $max <= 0 ) {
			return;
		}

		$table = $wpdb->prefix . 'relevant_logs';

		/*
		 * MySQL cannot read the delete target in a plain subquery: the inner
		 * SELECT is wrapped in a derived table. OFFSET %d lands on the id of
		 * the (cap + 1)-th newest row — everything at or below it is beyond
		 * the cap. With fewer rows than that the subquery yields NULL and
		 * nothing is deleted.
		 */
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $table . ' WHERE id <= ( SELECT id FROM ( SELECT id FROM ' . $table . ' ORDER BY id DESC LIMIT 1 OFFSET %d ) AS older_entries )',
				$max
			)
		);
	}
}
