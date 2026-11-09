<?php
/**
 * Migration: create the forms table.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Database\Migrations;

use RelevantForms\Database\Migration;

/**
 * Custom table holding form definitions (doc §49).
 */
final class CreateFormsTable implements Migration {

	/**
	 * Version identifier.
	 */
	public function version(): string {
		return '1.0.0';
	}

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'relevant_forms';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'draft',
			schema_json longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );
	}
}
