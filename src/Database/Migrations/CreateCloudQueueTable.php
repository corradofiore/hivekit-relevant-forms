<?php
/**
 * Migration: create the cloud retry queue table.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Database\Migrations;

use RelevantForms\Database\Migration;

/**
 * Durable queue for cloud work that may be retried: today the semantic
 * classification of a submission. Paid capabilities must never be dropped
 * because of a transient provider or network failure, so the request (its
 * already-redacted context) is stored here and replayed until it succeeds
 * or the window in `expires_at` passes.
 */
final class CreateCloudQueueTable implements Migration {

	/**
	 * Version identifier.
	 */
	public function version(): string {
		return '1.3.0';
	}

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'relevant_cloud_queue';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(40) NOT NULL DEFAULT 'classification',
			local_status varchar(20) NOT NULL DEFAULT 'new',
			payload longtext NOT NULL,
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			last_error varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			next_attempt_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			completed_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_next_attempt_at (status,next_attempt_at),
			KEY entry_id (entry_id),
			KEY kind (kind)
		) {$charset};";

		dbDelta( $sql );
	}
}
