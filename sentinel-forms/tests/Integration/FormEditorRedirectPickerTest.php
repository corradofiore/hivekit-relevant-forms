<?php
/**
 * Form editor redirect picker integration tests: the Redirect URL field is
 * complemented by a picker listing published pages and posts whose permalink
 * fills the URL field.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Admin\Pages\FormEditPage;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormSchema;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FormEditorRedirectPickerTest extends TestCase {

	/**
	 * Published pages and posts are offered, grouped, with their permalinks
	 * as option values.
	 */
	public function testPickerListsPublishedPagesAndPosts(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$pageId = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Thank You',
			]
		);
		$postId = self::factory()->post->create(
			[
				'post_type'  => 'post',
				'post_title' => 'Launch News',
			]
		);

		$html = $this->renderEditor();

		$this->assertStringContainsString( 'id="sf-redirect-page"', $html );
		$this->assertStringContainsString( '<optgroup label="Pages">', $html );
		$this->assertStringContainsString( '<optgroup label="Posts">', $html );

		$this->assertStringContainsString( '>Thank You</option>', $html );
		$this->assertStringContainsString( esc_attr( (string) get_permalink( $pageId ) ), $html );

		$this->assertStringContainsString( '>Launch News</option>', $html );
		$this->assertStringContainsString( esc_attr( (string) get_permalink( $postId ) ), $html );
	}

	/**
	 * Drafts and other non-public posts are not offered.
	 */
	public function testPickerSkipsUnpublishedContent(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Secret Draft',
				'post_status' => 'draft',
			]
		);

		$html = $this->renderEditor();

		$this->assertStringNotContainsString( 'Secret Draft', $html );
	}

	/**
	 * Without published content the picker is not rendered at all.
	 */
	public function testPickerIsHiddenWhenThereIsNoPublishedContent(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		foreach (
			get_posts(
				[
					'post_type'      => [ 'page', 'post' ],
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				]
			) as $postId
		) {
			wp_delete_post( (int) $postId, true );
		}

		$html = $this->renderEditor();

		$this->assertStringNotContainsString( 'sf-redirect-page', $html );
	}

	/**
	 * Render the editor for a created form.
	 */
	private function renderEditor(): string {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Redirected' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
			]
		);

		$_GET['form_id'] = $repository->create( 'Redirected', $schema, 'published' );

		ob_start();
		FormEditPage::render();

		return (string) ob_get_clean();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_GET['form_id'] );

		parent::tearDown();
	}
}
