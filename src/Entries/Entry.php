<?php
/**
 * Entry entity.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Entries;

/**
 * A stored submission.
 */
final class Entry {

	/**
	 * Workflow statuses (doc §11).
	 */
	public const STATUS_NEW        = 'new';
	public const STATUS_READ       = 'read';
	public const STATUS_ARCHIVED   = 'archived';
	public const STATUS_SPAM       = 'spam';
	public const STATUS_SUSPICIOUS = 'suspicious';
	public const STATUS_PRIORITY   = 'priority';

	/**
	 * Payment statuses (payments doc §4).
	 */
	public const STATUS_PENDING_PAYMENT = 'pending_payment';
	public const STATUS_PAID            = 'paid';
	public const STATUS_PAYMENT_FAILED  = 'payment_failed';
	public const STATUS_REFUNDED        = 'refunded';

	/**
	 * Allowed workflow statuses.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		self::STATUS_NEW,
		self::STATUS_READ,
		self::STATUS_ARCHIVED,
		self::STATUS_SPAM,
		self::STATUS_SUSPICIOUS,
		self::STATUS_PRIORITY,
		self::STATUS_PENDING_PAYMENT,
		self::STATUS_PAID,
		self::STATUS_PAYMENT_FAILED,
		self::STATUS_REFUNDED,
	];

	/**
	 * Constructor.
	 *
	 * @param int|null             $id             Entry ID (null before persistence).
	 * @param int                  $formId         Owning form ID.
	 * @param int                  $formVersionId  Schema version snapshot ID.
	 * @param string               $status         Workflow status.
	 * @param string               $sourceUrl      Page the form was submitted from.
	 * @param string               $referrer       HTTP referer.
	 * @param string               $userAgentHash  Salted hash of the user agent.
	 * @param string               $ipHash         Salted hash of the client IP.
	 * @param string               $createdAt      MySQL datetime.
	 * @param string               $updatedAt      MySQL datetime.
	 * @param array<string, mixed> $meta           Field values keyed by field id.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly int $formId,
		public readonly int $formVersionId,
		public readonly string $status,
		public readonly string $sourceUrl,
		public readonly string $referrer,
		public readonly string $userAgentHash,
		public readonly string $ipHash,
		public readonly string $createdAt,
		public readonly string $updatedAt,
		public readonly array $meta = [],
	) {}

	/**
	 * Hydrate from a database row.
	 *
	 * @param object               $row  Row with entry columns.
	 * @param array<string, mixed> $meta Field values.
	 */
	public static function fromRow( object $row, array $meta = [] ): self {
		return new self(
			(int) $row->id,
			(int) $row->form_id,
			(int) $row->form_version_id,
			(string) $row->status,
			(string) ( $row->source_url ?? '' ),
			(string) ( $row->referrer ?? '' ),
			(string) ( $row->user_agent_hash ?? '' ),
			(string) ( $row->ip_hash ?? '' ),
			(string) $row->created_at,
			(string) $row->updated_at,
			$meta,
		);
	}
}
