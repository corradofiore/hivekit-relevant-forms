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
	private const LEVELS = [ 'error', 'warning', 'info', 'debug' ];

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
}
