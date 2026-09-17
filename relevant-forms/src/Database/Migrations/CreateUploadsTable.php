<?php
/**
 * Migration: create the uploads table.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Database\Migrations;

use RelevantForms\Database\Migration;

/**
 * Upload metadata records (doc §43). Object bytes live in object storage;
 * this table stores references, not paths.
 */
final class CreateUploadsTable implements Migration {

	/**
	 * Version identifier.
	 */
	public function version(): string {
		return '1.1.0';
	}

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'relevant_uploads';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			field_id varchar(190) NOT NULL DEFAULT '',
			entry_id bigint(20) unsigned NULL,
			provider varchar(40) NOT NULL DEFAULT 'local',
			storage_location varchar(190) NOT NULL DEFAULT '',
			object_key varchar(512) NOT NULL DEFAULT '',
			original_filename varchar(512) NOT NULL DEFAULT '',
			extension varchar(20) NOT NULL DEFAULT '',
			declared_mime varchar(100) NOT NULL DEFAULT '',
			detected_mime varchar(100) NOT NULL DEFAULT '',
			size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			checksum_sha256 varchar(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			scan_status varchar(20) NOT NULL DEFAULT 'unscanned',
			scan_engine varchar(100) NOT NULL DEFAULT '',
			scan_reference varchar(190) NOT NULL DEFAULT '',
			uploaded_at datetime NULL,
			scan_completed_at datetime NULL,
			retention_expires_at datetime NULL,
			deleted_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY entry_id (entry_id),
			KEY form_id (form_id),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );
	}
}
