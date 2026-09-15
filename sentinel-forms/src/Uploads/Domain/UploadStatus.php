<?php
/**
 * Upload security state machine (doc §34).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Domain;

/**
 * Lifecycle states for uploaded objects. Files start as pending, become
 * quarantined after upload and validation, and may only be marked clean by
 * the malware-scanning pipeline (Phase 3).
 */
final class UploadStatus {

	/**
	 * Authorization issued; object not yet uploaded.
	 */
	public const PENDING = 'pending';

	/**
	 * Bytes received; awaiting validation.
	 */
	public const UPLOADED = 'uploaded';

	/**
	 * Validated; held until scanning succeeds or policy permits.
	 */
	public const QUARANTINED = 'quarantined';

	/**
	 * Scan in progress (Phase 3).
	 */
	public const SCANNING = 'scanning';

	/**
	 * Verified safe.
	 */
	public const CLEAN = 'clean';

	/**
	 * Rejected by validation.
	 */
	public const REJECTED = 'rejected';

	/**
	 * Malware detected.
	 */
	public const INFECTED = 'infected';

	/**
	 * Retention period elapsed.
	 */
	public const EXPIRED = 'expired';

	/**
	 * Removed from storage.
	 */
	public const DELETED = 'deleted';

	/**
	 * Processing failure.
	 */
	public const ERROR = 'error';

	/**
	 * All known states.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		self::PENDING,
		self::UPLOADED,
		self::QUARANTINED,
		self::SCANNING,
		self::CLEAN,
		self::REJECTED,
		self::INFECTED,
		self::EXPIRED,
		self::DELETED,
		self::ERROR,
	];

	/**
	 * Allowed transitions (doc §34).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const TRANSITIONS = [
		self::PENDING     => [ self::PENDING, self::UPLOADED, self::QUARANTINED, self::REJECTED, self::ERROR, self::DELETED ],
		self::UPLOADED    => [ self::QUARANTINED, self::REJECTED, self::ERROR ],
		self::QUARANTINED => [ self::SCANNING, self::CLEAN, self::ERROR, self::EXPIRED, self::DELETED ],
		self::SCANNING    => [ self::CLEAN, self::INFECTED, self::QUARANTINED, self::ERROR ],
		self::CLEAN       => [ self::EXPIRED, self::DELETED, self::SCANNING ],
		self::INFECTED    => [ self::DELETED, self::EXPIRED, self::SCANNING ],
		self::REJECTED    => [ self::DELETED, self::EXPIRED ],
		self::EXPIRED     => [ self::DELETED ],
		self::ERROR       => [ self::QUARANTINED, self::DELETED ],
	];

	/**
	 * Whether a transition is allowed.
	 */
	public static function canTransition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? [], true );
	}

	/**
	 * Whether the state permits an administrator download.
	 */
	public static function isDownloadable( string $status, bool $allowUnscanned = false ): bool {
		if ( self::CLEAN === $status ) {
			return true;
		}

		return $allowUnscanned && self::QUARANTINED === $status;
	}
}
