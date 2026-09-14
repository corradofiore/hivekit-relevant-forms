<?php
/**
 * Migration: create the logs table.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Database\Migrations;

use SentinelForms\Database\Migration;

/**
 * Structured plugin logs (doc §58). Debug logging is opt-in.
 */
final class CreateLogsTable implements Migration {

	/**
	 * Version identifier.
	 */
	public function version(): string {
		return '1.0.5';
	}

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'sentinel_logs';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(20) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY level (level)
		) {$charset};";

		dbDelta( $sql );
	}
}
