<?php
/**
 * Pest configuration.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use RelevantForms\Tests\Unit\TestCase;

/*
|--------------------------------------------------------------------------
| Unit test case
|--------------------------------------------------------------------------
*/
uses( TestCase::class )->in( 'Unit' );
uses()->group( 'unit' )->in( 'Unit' );
