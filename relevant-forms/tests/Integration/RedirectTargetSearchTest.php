<?php
/**
 * Redirect target AJAX search integration tests: the endpoint behind the
 * form editor's search selector for large sites.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\RedirectTargetSearchController;
use RelevantForms\Tests\Integration\Support\AjaxRequests;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class RedirectTargetSearchTest extends TestCase {

	/**
	 * Matches published pages and posts, ignoring drafts and unrelated items.
	 */
	public function testSearchFindsMatchingPagesAndPosts(): void {
		$pageId      = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Thank You',
			]
		);
		$postId      = self::factory()->post->create(
			[
				'post_type'  => 'post',
				'post_title' => 'Thanks for the launch',
			]
		);
		$unrelatedId = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Unrelated',
			]
		);
		$draftId     = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Thanks draft',
				'post_status' => 'draft',
			]
		);

		$ids = array_column( RedirectTargetSearchController::search( 'thank' ), 'id' );

		$this->assertContains( $pageId, $ids );
		$this->assertContains( $postId, $ids );
		$this->assertNotContains( $unrelatedId, $ids );
		$this->assertNotContains( $draftId, $ids );
	}

	/**
	 * Short terms return nothing so typing stays cheap.
	 */
	public function testSearchRequiresTwoCharacters(): void {
		self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Thank You',
			]
		);

		$this->assertSame( [], RedirectTargetSearchController::search( 't' ) );
		$this->assertSame( [], RedirectTargetSearchController::search( '   ' ) );
	}

	/**
	 * The AJAX endpoint returns the matches as JSON for allowed users.
	 */
	public function testAjaxEndpointReturnsJson(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Thank You',
			]
		);

		$_GET['search']       = 'thank';
		$_REQUEST['search']   = 'thank';
		$_REQUEST['_wpnonce'] = wp_create_nonce( RedirectTargetSearchController::ACTION );

		$body = AjaxRequests::dispatch( [ RedirectTargetSearchController::class, 'handle' ] );

		$this->assertTrue( $body['success'] );
		$this->assertCount( 1, $body['data']['results'] );
		$this->assertSame( 'Thank You', $body['data']['results'][0]['title'] );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_GET['search'], $_REQUEST['search'], $_REQUEST['_wpnonce'] );

		parent::tearDown();
	}
}
