<?php
/**
 * Migration: create the events table.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Database\Migrations;

use SentinelForms\Database\Migration;

/**
 * Domain events such as notification attempts (doc §49).
 */
final class CreateEventsTable implements Migration {

	/**
	 * Version identifier.
	 */
	public function version(): string {
		return '1.0.4';
	}

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'sentinel_events';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entry_id bigint(20) unsigned NULL,
			event varchar(190) NOT NULL DEFAULT '',
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY entry_id (entry_id),
			KEY event (event)
		) {$charset};";

		dbDelta( $sql );
	}
}
