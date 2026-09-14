<?php
/**
 * Migration: create the entry_meta table.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Database\Migrations;

use SentinelForms\Database\Migration;

/**
 * Per-field values attached to entries (doc §49).
 */
final class CreateEntryMetaTable implements Migration {

	/**
	 * Version identifier.
	 */
	public function version(): string {
		return '1.0.3';
	}

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'sentinel_entry_meta';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entry_id bigint(20) unsigned NOT NULL,
			meta_key varchar(190) NOT NULL DEFAULT '',
			meta_value longtext NULL,
			PRIMARY KEY  (meta_id),
			KEY entry_id (entry_id),
			KEY meta_key (meta_key)
		) {$charset};";

		dbDelta( $sql );
	}
}
