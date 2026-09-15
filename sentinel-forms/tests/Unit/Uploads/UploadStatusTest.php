<?php
/**
 * UploadStatus state machine tests.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use SentinelForms\Uploads\Domain\UploadStatus;

it(
	'allows the documented transitions',
	function () {
		expect( UploadStatus::canTransition( UploadStatus::PENDING, UploadStatus::UPLOADED ) )->toBeTrue();
		expect( UploadStatus::canTransition( UploadStatus::UPLOADED, UploadStatus::QUARANTINED ) )->toBeTrue();
		expect( UploadStatus::canTransition( UploadStatus::QUARANTINED, UploadStatus::SCANNING ) )->toBeTrue();
		expect( UploadStatus::canTransition( UploadStatus::SCANNING, UploadStatus::CLEAN ) )->toBeTrue();
		expect( UploadStatus::canTransition( UploadStatus::CLEAN, UploadStatus::DELETED ) )->toBeTrue();
		expect( UploadStatus::canTransition( UploadStatus::PENDING, UploadStatus::QUARANTINED ) )->toBeTrue();
		expect( UploadStatus::canTransition( UploadStatus::PENDING, UploadStatus::REJECTED ) )->toBeTrue();
		expect( UploadStatus::canTransition( UploadStatus::CLEAN, UploadStatus::SCANNING ) )->toBeTrue();
		expect( UploadStatus::canTransition( UploadStatus::INFECTED, UploadStatus::SCANNING ) )->toBeTrue();
	}
);

it(
	'rejects invalid transitions',
	function () {
		expect( UploadStatus::canTransition( UploadStatus::QUARANTINED, UploadStatus::CLEAN ) )->toBeTrue(); // Admin override path.
		expect( UploadStatus::canTransition( UploadStatus::PENDING, UploadStatus::CLEAN ) )->toBeFalse();
		expect( UploadStatus::canTransition( UploadStatus::CLEAN, UploadStatus::QUARANTINED ) )->toBeFalse();
		expect( UploadStatus::canTransition( UploadStatus::PENDING, 'nonsense' ) )->toBeFalse();
	}
);

it(
	'gates downloads on scan state and policy',
	function () {
		expect( UploadStatus::isDownloadable( UploadStatus::CLEAN ) )->toBeTrue();
		expect( UploadStatus::isDownloadable( UploadStatus::QUARANTINED, true ) )->toBeTrue();
		expect( UploadStatus::isDownloadable( UploadStatus::QUARANTINED ) )->toBeFalse();
		expect( UploadStatus::isDownloadable( UploadStatus::QUARANTINED, false ) )->toBeFalse();
		expect( UploadStatus::isDownloadable( UploadStatus::PENDING, true ) )->toBeFalse();
		expect( UploadStatus::isDownloadable( UploadStatus::INFECTED, true ) )->toBeFalse();
	}
);
