<?php
/**
 * Database migration contract.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Database;

/**
 * A single ordered schema migration.
 */
interface Migration {

	/**
	 * Unique ordered version identifier, e.g. "1.0.0".
	 */
	public function version(): string;

	/**
	 * Apply the migration (idempotent via dbDelta).
	 */
	public function up(): void;
}
