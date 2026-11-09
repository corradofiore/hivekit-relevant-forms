<?php
/**
 * Migration: add resume-token columns to the entries table.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Database\Migrations;

use RelevantForms\Database\Migration;

/**
 * Save and Resume state on partial entries (docs/database.md): the resume
 * token hash, requested email, expiry, saved wizard page and last-sent time.
 * dbDelta adds the new columns and key on existing installs.
 */
final class AddResumeColumnsToEntries implements Migration {

	/**
	 * Version identifier.
	 */
	public function version(): string {
		return '1.2.0';
	}

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'relevant_entries';
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
			resume_token_hash varchar(64) NULL,
			resume_email varchar(190) NULL,
			resume_expires_at datetime NULL,
			resume_page int unsigned NULL,
			resume_email_sent_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY status (status),
			KEY resume_token_hash (resume_token_hash)
		) {$charset};";

		dbDelta( $sql );
	}
}
