<?php
/**
 * Form editor redirect picker integration tests: the picker anchors the
 * redirect to a page/post ID, and switches to an AJAX search selector when
 * the site has more than 30 published pages and posts.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\FormSaveController;
use RelevantForms\Admin\Pages\FormEditPage;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Tests\Integration\Support\CapturesRedirects;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FormEditorRedirectPickerTest extends TestCase {

	use CapturesRedirects;

	/**
	 * Published pages and posts are offered, grouped, with their IDs as
	 * option values — the ID is the immutable anchor.
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

		$this->assertStringContainsString( 'id="rf-redirect-page"', $html );
		$this->assertStringContainsString( 'name="redirect_page"', $html );
		$this->assertStringContainsString( '<optgroup label="Pages">', $html );
		$this->assertStringContainsString( '<optgroup label="Posts">', $html );

		$this->assertMatchesRegularExpression( '/value="' . $pageId . '"[^>]*>Thank You<\/option>/', $html );
		$this->assertMatchesRegularExpression( '/value="' . $postId . '"[^>]*>Launch News<\/option>/', $html );

		// The URL field stays editable while no anchor is selected.
		$this->assertDoesNotMatchRegularExpression( '/<input type="url"[^>]*disabled/', $html );
		$this->assertStringContainsString( 'placeholder="https://..."', $html );
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

		$this->removePublishedContent();

		$html = $this->renderEditor();

		$this->assertStringNotContainsString( 'rf-redirect-page', $html );
		$this->assertStringNotContainsString( 'rf-redirect-search', $html );
	}

	/**
	 * Above 30 published targets the picker becomes a search selector; at 30
	 * and below it stays a plain grouped drop-down.
	 */
	public function testSearchSelectorKicksInAboveThirtyTargets(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->removePublishedContent();

		for ( $i = 1; $i <= 30; $i++ ) {
			self::factory()->post->create(
				[
					'post_type'  => 'post',
					'post_title' => 'Bulk ' . $i,
				]
			);
		}

		$html = $this->renderEditor();

		$this->assertStringNotContainsString( 'rf-redirect-search', $html );
		$this->assertStringContainsString( '<optgroup label="Posts">', $html );

		self::factory()->post->create(
			[
				'post_type'  => 'post',
				'post_title' => 'Bulk 31',
			]
		);

		$html = $this->renderEditor();

		$this->assertStringContainsString( 'id="rf-redirect-search"', $html );
		$this->assertStringContainsString( 'type="search"', $html );
		$this->assertStringNotContainsString( '<optgroup', $html );
	}

	/**
	 * In search mode the current anchor is shown in the search box and the
	 * drop-down ships only the placeholder and the selection.
	 */
	public function testSearchSelectorShowsTheCurrentAnchor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$anchorId = 0;

		for ( $i = 1; $i <= 31; $i++ ) {
			$createdId = self::factory()->post->create(
				[
					'post_type'  => 'post',
					'post_title' => 'Bulk ' . $i,
				]
			);

			if ( 5 === $i ) {
				$anchorId = $createdId;
			}
		}

		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Anchored' )->withConfirmation( '', '', $anchorId );

		$_GET['form_id'] = $repository->create( 'Anchored', $schema, 'published' );

		ob_start();
		FormEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="rf-redirect-search"', $html );
		$this->assertStringContainsString( 'value="Bulk 5"', $html );
		$this->assertMatchesRegularExpression( '/value="' . $anchorId . '"[^>]*selected/', $html );

		// The drop-down ships only the placeholder and the selection.
		$this->assertSame( 1, preg_match( '/<select id="rf-redirect-page" name="redirect_page">(.*?)<\/select>/s', $html, $matches ) );
		$this->assertSame( 2, substr_count( $matches[1], '<option' ) );

		$this->assertMatchesRegularExpression( '/value="page"[^>]*selected/', $html );
	}

	/**
	 * A saved anchor preselects its item, checks the page/post mode and
	 * leaves the URL field editable (the rows are hidden by JS, not disabled).
	 */
	public function testSavedAnchorPreselectsTheItem(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$pageId = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Thank You',
			]
		);

		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Anchored' )->withConfirmation( '', '', $pageId );

		$_GET['form_id'] = $repository->create( 'Anchored', $schema, 'published' );

		ob_start();
		FormEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="' . $pageId . '"[^>]*selected/', $html );
		$this->assertMatchesRegularExpression( '/value="page"[^>]*selected/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<input type="url"[^>]*disabled/', $html );
	}

	/**
	 * Saving an anchor persists the page ID; the stored URL is kept (the
	 * disabled input is not submitted) for when the anchor is cleared.
	 */
	public function testSavingAnAnchorPersistsThePageIdAndKeepsTheUrl(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$pageId = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Thank You',
			]
		);

		$repository = new FormRepository();
		$schema     = FormSchema::empty( 'Legacy' )->withConfirmation( '', 'https://example.com/legacy' );
		$formId     = $repository->create( 'Legacy', $schema, 'published' );

		$_POST = [
			'form_id'           => $formId,
			'form_name'         => 'Legacy',
			'form_status'       => 'published',
			'confirmation_mode' => 'page',
			'redirect_page'     => (string) $pageId,
		];

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_form_save' );

		$url = $this->handleAndCaptureRedirect();

		$this->assertStringContainsString( 'saved=1', $url );

		$saved = $repository->find( $formId );
		$this->assertSame( 'page', $saved->schema->confirmationMode() );
		$this->assertSame( $pageId, $saved->schema->redirectPageId() );
		$this->assertSame( 'https://example.com/legacy', $saved->schema->redirectUrl() );
	}

	/**
	 * The confirmation section renders the three mutually exclusive modes as
	 * a drop-down, with "message" as the default for a new form.
	 */
	public function testConfirmationModesRenderAsDropdown(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Thank You',
			]
		);

		$html = $this->renderEditor();

		$this->assertStringContainsString( 'Show a confirmation message', $html );
		$this->assertStringContainsString( 'Redirect to a URL', $html );
		$this->assertStringContainsString( 'Redirect to a page or post', $html );

		$this->assertMatchesRegularExpression( '/<select id="relevant-confirmation-mode" name="confirmation_mode">/', $html );
		$this->assertMatchesRegularExpression( '/value="message"[^>]*selected/', $html );

		$this->assertSame( 1, preg_match( '/<select id="relevant-confirmation-mode" name="confirmation_mode">(.*?)<\/select>/s', $html, $matches ) );
		$this->assertSame( 3, substr_count( $matches[1], '<option' ) );

		$this->assertStringContainsString( 'id="rf-confirmation-message-row"', $html );
		$this->assertStringContainsString( 'id="rf-confirmation-url-row"', $html );
		$this->assertStringContainsString( 'id="rf-confirmation-page-row"', $html );
	}

	/**
	 * Run the save controller and capture the redirect target.
	 */
	private function handleAndCaptureRedirect(): string {
		return $this->captureRedirect( [ FormSaveController::class, 'handle' ] );
	}

	/**
	 * Trash and delete every published page and post.
	 */
	private function removePublishedContent(): void {
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
		unset( $_GET['form_id'], $_REQUEST['_wpnonce'] );
		remove_all_filters( 'wp_redirect' );

		parent::tearDown();
	}
}
