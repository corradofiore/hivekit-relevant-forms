<?php
/**
 * Front-end (in-page) submission integration tests: rendered forms post to
 * their own page, validation errors render inline on the themed page with
 * the entered values preserved, and successes redirect back (PRG).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Entries\EntryRepository;
use SentinelForms\Forms\Form;
use SentinelForms\Forms\FormRenderer;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormSchema;
use SentinelForms\Forms\SubmissionFeedback;
use SentinelForms\Http\SubmissionHandler;
use SentinelForms\Tests\Integration\Support\RedirectedException;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FrontendSubmissionTest extends TestCase {

	/**
	 * Simulated POST data is part of the handler contract; reset it between tests.
	 */
	public function setUp(): void {
		parent::setUp();

		SubmissionFeedback::reset();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/sentinel-demo/';
		$_SERVER['HTTP_REFERER']   = home_url( '/sentinel-demo/' );
	}

	/**
	 * The rendered markup posts to the page itself, never to wp-admin.
	 */
	public function testRenderedFormPostsToItsOwnPage(): void {
		$form = $this->makePublishedForm();

		$html = FormRenderer::renderForm( $form );

		$this->assertStringContainsString( 'action=""', $html );
		$this->assertStringNotContainsString( 'wp-admin', $html );
	}

	/**
	 * Validation errors render on the page with the entered values kept.
	 */
	public function testFrontEndValidationErrorsRenderInline(): void {
		$form = $this->makePublishedForm();

		$_POST = [
			'action'                   => 'sentinel_forms_submit',
			'_sentinel_form_id'        => $form->id,
			'_sentinel_form_nonce'     => wp_create_nonce( 'sentinel_form_' . $form->id ),
			'sentinel_field_f_name'    => '',
			'sentinel_field_f_email'   => 'not-an-email',
			'sentinel_field_f_message' => 'Hello there',
		];

		SubmissionHandler::maybeHandleFrontendSubmission();

		// Nothing stored; the request continues so the page can render.
		$this->assertCount( 0, ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] ) );

		// The shortcode re-render picks the stashed feedback up automatically.
		$html = FormRenderer::renderForm( $form );

		$this->assertStringContainsString( 'sentinel-forms-error', $html );
		$this->assertStringContainsString( 'This field is required.', $html );
		$this->assertStringContainsString( 'Please enter a valid email address.', $html );
		$this->assertStringContainsString( 'value="not-an-email"', $html );
		$this->assertStringContainsString( 'Hello there', $html );
	}

	/**
	 * A valid front-end submission is stored and redirects back (PRG), even
	 * though the referer equals the current request URL (the front-end flow).
	 */
	public function testFrontEndSuccessStoresTheEntryAndRedirectsBack(): void {
		$form = $this->makePublishedForm();

		$_POST = [
			'action'                   => 'sentinel_forms_submit',
			'_sentinel_form_id'        => $form->id,
			'_sentinel_form_nonce'     => wp_create_nonce( 'sentinel_form_' . $form->id ),
			'_wp_http_referer'         => '/sentinel-demo/',
			'sentinel_field_f_name'    => 'Ada Lovelace',
			'sentinel_field_f_email'   => 'ada@example.com',
			'sentinel_field_f_message' => 'Boiler is broken.',
		];
		// Real requests populate $_REQUEST from the GET/POST order.
		$_REQUEST['_wp_http_referer'] = '/sentinel-demo/';

		$url = $this->frontendSubmitAndCaptureRedirect();

		$this->assertStringContainsString( '/sentinel-demo/?sentinel_form_success=' . $form->id, $url );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'Ada Lovelace', $entries[0]->meta['f_name'] );
		$this->assertSame( 'ada@example.com', $entries[0]->meta['f_email'] );
	}

	/**
	 * A triggered honeypot pretends success but stores nothing.
	 */
	public function testFrontEndHoneypotPretendsSuccessWithoutStoring(): void {
		$form = $this->makePublishedForm();

		$_POST = [
			'action'                   => 'sentinel_forms_submit',
			'_sentinel_form_id'        => $form->id,
			'_sentinel_form_nonce'     => wp_create_nonce( 'sentinel_form_' . $form->id ),
			'_sentinel_hp'             => 'https://spam.example',
			'sentinel_field_f_name'    => 'Spambot',
			'sentinel_field_f_email'   => 'bot@example.com',
			'sentinel_field_f_message' => 'Buy links!',
		];

		$url = $this->frontendSubmitAndCaptureRedirect();

		$this->assertStringContainsString( 'sentinel_form_success=' . $form->id, $url );
		$this->assertCount( 0, ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] ) );
	}

	/**
	 * GET requests are never processed by the front-end handler.
	 */
	public function testGetRequestsAreIgnored(): void {
		$form = $this->makePublishedForm();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = [
			'action'            => 'sentinel_forms_submit',
			'_sentinel_form_id' => $form->id,
		];

		SubmissionHandler::maybeHandleFrontendSubmission();

		$this->assertSame( [], SubmissionFeedback::errors( (int) $form->id ) );
		$this->assertCount( 0, ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] ) );
	}

	/**
	 * A redirect anchored to a page keeps working when the page's slug changes:
	 * the permalink is resolved from the stored ID at submission time.
	 */
	public function testRedirectAnchorFollowsThePageWhenItsSlugChanges(): void {
		$original = (string) get_option( 'permalink_structure' );
		$this->set_permalink_structure( '/%postname%/' );

		try {
			$pageId = self::factory()->post->create(
				[
					'post_type'  => 'page',
					'post_title' => 'Thank You',
					'post_name'  => 'thank-you',
				]
			);

			$form = $this->makeAnchoredForm( $pageId );

			$this->assertSame( get_permalink( $pageId ), $this->submitAnchored( $form ) );

			wp_update_post(
				[
					'ID'        => $pageId,
					'post_name' => 'thank-you-renamed',
				]
			);

			$this->assertStringContainsString( 'thank-you-renamed', (string) get_permalink( $pageId ) );
			$this->assertSame( get_permalink( $pageId ), $this->submitAnchored( $form ) );
		} finally {
			$this->set_permalink_structure( $original );
		}
	}

	/**
	 * The URL mode wins over a stored anchor.
	 */
	public function testRedirectModeUrlWinsOverTheAnchor(): void {
		$pageId = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Thank You',
			]
		);

		$form = $this->makeAnchoredForm( $pageId, 'https://example.com/legacy', 'url' );

		$this->assertSame( 'https://example.com/legacy', $this->submitAnchored( $form ) );
	}

	/**
	 * The message mode ignores stored redirect values.
	 */
	public function testRedirectModeMessageIgnoresStoredRedirects(): void {
		$pageId = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Thank You',
			]
		);

		$form = $this->makeAnchoredForm( $pageId, 'https://example.com/legacy', 'message' );

		$this->assertStringContainsString( 'sentinel_form_success=' . $form->id, $this->submitAnchored( $form ) );
	}

	/**
	 * Submit valid data for a form and capture the redirect target.
	 *
	 * @param Form $form Form to submit.
	 */
	private function submitAnchored( Form $form ): string {
		$_POST = [
			'action'                => 'sentinel_forms_submit',
			'_sentinel_form_id'     => $form->id,
			'_sentinel_form_nonce'  => wp_create_nonce( 'sentinel_form_' . $form->id ),
			'sentinel_field_f_name' => 'Ada Lovelace',
		];

		return $this->frontendSubmitAndCaptureRedirect();
	}

	/**
	 * Create a published form with one field and a redirect configuration.
	 *
	 * @param int         $pageId Redirect anchor page ID.
	 * @param string      $url    Stored redirect URL.
	 * @param string|null $mode   Confirmation mode (inferred when omitted).
	 */
	private function makeAnchoredForm( int $pageId, string $url = '', ?string $mode = null ): Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Anchored' )
			->withFields(
				[
					[
						'id'       => 'f_name',
						'type'     => 'text',
						'label'    => 'Name',
						'required' => true,
					],
				]
			)
			->withConfirmation( '', $url, $pageId, $mode );

		$id = $repository->create( 'Anchored', $schema, 'published' );

		return $repository->find( $id );
	}

	/**
	 * Run the front-end handler and capture the redirect target.
	 */
	private function frontendSubmitAndCaptureRedirect(): string {
		add_filter(
			'sentinel_forms_redirect',
			static function ( string $url ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
				throw new RedirectedException( $url );
			}
		);

		try {
			SubmissionHandler::maybeHandleFrontendSubmission();
			$this->fail( 'The handler should redirect after a successful submission.' );
		} catch ( RedirectedException $redirected ) {
			return $redirected->url;
		} finally {
			remove_all_filters( 'sentinel_forms_redirect' );
		}
	}

	/**
	 * Create a published form with three fields.
	 */
	private function makePublishedForm(): Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Emergency Request' )->withFields(
			[
				[
					'id'       => 'f_name',
					'type'     => 'text',
					'label'    => 'Name',
					'required' => true,
				],
				[
					'id'       => 'f_email',
					'type'     => 'email',
					'label'    => 'Email',
					'required' => true,
				],
				[
					'id'       => 'f_message',
					'type'     => 'textarea',
					'label'    => 'Message',
					'required' => true,
				],
			]
		);

		$id = $repository->create( 'Emergency Request', $schema, 'published' );

		return $repository->find( $id );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		SubmissionFeedback::reset();
		remove_all_filters( 'sentinel_forms_redirect' );
		unset( $_REQUEST['_wp_http_referer'] );

		parent::tearDown();
	}
}
