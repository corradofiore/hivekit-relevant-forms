<?php
/**
 * Migration: create the entries table.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Database\Migrations;

use SentinelForms\Database\Migration;

/**
 * Submitted entries (doc §10, §49). Field values live in entry_meta.
 */
final class CreateEntriesTable implements Migration {

	/**
	 * Version identifier.
	 */
	public function version(): string {
		return '1.0.2';
	}

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'sentinel_entries';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			form_version_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'new',
			source_url text NULL,
			referrer text NULL,
			user_agent_hash varchar(64) NULL,
			ip_hash varchar(64) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );
	}
}
