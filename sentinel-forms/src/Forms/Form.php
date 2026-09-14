<?php
/**
 * Form entity.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Forms;

/**
 * A stored form: database row plus parsed schema.
 */
final class Form {

	/**
	 * Constructor.
	 *
	 * @param int        $id        Form ID.
	 * @param string     $name      Display name.
	 * @param string     $status    draft|published|archived.
	 * @param FormSchema $schema    Parsed canonical schema.
	 * @param string     $createdAt MySQL datetime.
	 * @param string     $updatedAt MySQL datetime.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $status,
		public readonly FormSchema $schema,
		public readonly string $createdAt,
		public readonly string $updatedAt,
	) {}

	/**
	 * Hydrate from a database row.
	 *
	 * @param object $row Row with id, name, status, schema_json, created_at, updated_at.
	 */
	public static function fromRow( object $row ): self {
		$decoded = json_decode( (string) $row->schema_json, true );

		return new self(
			(int) $row->id,
			(string) $row->name,
			(string) $row->status,
			FormSchema::fromArray( is_array( $decoded ) ? $decoded : [] ),
			(string) $row->created_at,
			(string) $row->updated_at,
		);
	}
}
