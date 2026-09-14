<?php
/**
 * Migration: create the form_versions table.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Database\Migrations;

use SentinelForms\Database\Migration;

/**
 * Versioned snapshots of form schemas (doc §50).
 */
final class CreateFormVersionsTable implements Migration {

	/**
	 * Version identifier.
	 */
	public function version(): string {
		return '1.0.1';
	}

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'sentinel_form_versions';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			version int(11) NOT NULL DEFAULT 1,
			schema_json longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id)
		) {$charset};";

		dbDelta( $sql );
	}
}
