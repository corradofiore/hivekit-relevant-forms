<?php
/**
 * SubmissionFeedback unit tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use RelevantForms\Forms\SubmissionFeedback;

beforeEach(
	function () {
		SubmissionFeedback::reset();
	}
);

it(
	'stashes and retrieves per-form submission feedback',
	function () {
		SubmissionFeedback::stash( 1, [ 'f_name' => 'This field is required.' ], [ 'f_name' => '' ] );
		SubmissionFeedback::stash( 2, [ 'f_email' => 'Please enter a valid email address.' ], [ 'f_email' => 'x' ] );

		expect( SubmissionFeedback::errors( 1 ) )->toBe( [ 'f_name' => 'This field is required.' ] );
		expect( SubmissionFeedback::values( 1 ) )->toBe( [ 'f_name' => '' ] );
		expect( SubmissionFeedback::errors( 2 ) )->toBe( [ 'f_email' => 'Please enter a valid email address.' ] );
		expect( SubmissionFeedback::values( 3 ) )->toBe( [] );
	}
);

it(
	'resets one form or everything',
	function () {
		SubmissionFeedback::stash( 1, [ 'a' => 'x' ], [ 'a' => '1' ] );
		SubmissionFeedback::stash( 2, [ 'b' => 'y' ], [ 'b' => '2' ] );

		SubmissionFeedback::reset( 1 );

		expect( SubmissionFeedback::errors( 1 ) )->toBe( [] );
		expect( SubmissionFeedback::errors( 2 ) )->toBe( [ 'b' => 'y' ] );

		SubmissionFeedback::reset();

		expect( SubmissionFeedback::errors( 2 ) )->toBe( [] );
		expect( SubmissionFeedback::values( 2 ) )->toBe( [] );
	}
);
