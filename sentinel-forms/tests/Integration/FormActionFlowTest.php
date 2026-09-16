<?php
/**
 * Form row-action flow integration tests: nonce-signed links arrive as GET
 * on admin-post.php and perform duplicate/archive.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Admin\Controllers\FormActionController;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormSchema;
use SentinelForms\Tests\Integration\Support\RedirectedException;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FormActionFlowTest extends TestCase {

	/**
	 * The Duplicate row action creates a copy and redirects back to the list.
	 */
	public function testDuplicateRowActionCreatesACopy(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm( 'Emergency Request' );

		$this->signRequest( 'duplicate', $formId );

		$url = $this->handleAndCaptureRedirect();

		$this->assertStringContainsString( 'page=sentinel-forms', $url );
		$this->assertCount( 2, ( new FormRepository() )->all() );
	}

	/**
	 * The Archive row action archives the form and redirects back to the list.
	 */
	public function testArchiveRowActionArchivesTheForm(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm( 'Old Form' );

		$this->signRequest( 'archive', $formId );

		$url = $this->handleAndCaptureRedirect();

		$this->assertStringContainsString( 'page=sentinel-forms', $url );
		$this->assertSame( 'archived', ( new FormRepository() )->find( $formId )->status );
	}

	/**
	 * A tampered nonce is rejected.
	 */
	public function testInvalidNonceIsRejected(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm( 'Guarded' );

		$this->signRequest( 'duplicate', $formId, 'bogus' );

		$this->expectException( \WPDieException::class );

		FormActionController::handle();
	}

	/**
	 * Populate the request as the nonce-signed row-action link would.
	 */
	private function signRequest( string $action, int $formId, ?string $nonce = null ): void {
		$_REQUEST = [
			'action'      => 'sentinel_forms_action',
			'form_action' => $action,
			'form_id'     => $formId,
			'_wpnonce'    => $nonce ?? wp_create_nonce( 'sentinel_form_action_' . $action . '_' . $formId ),
		];
	}

	/**
	 * Run the controller and capture the redirect target.
	 */
	private function handleAndCaptureRedirect(): string {
		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
				throw new RedirectedException( $location );
			}
		);

		try {
			FormActionController::handle();
			$this->fail( 'The controller should redirect.' );
		} catch ( RedirectedException $redirected ) {
			return $redirected->url;
		} finally {
			remove_all_filters( 'wp_redirect' );
		}
	}

	/**
	 * Create a published form with one field.
	 */
	private function makeForm( string $name ): int {
		$repository = new FormRepository();

		$schema = FormSchema::empty( $name )->withFields(
			[
				[
					'id'       => 'f_name',
					'type'     => 'text',
					'label'    => 'Name',
					'required' => true,
				],
			]
		);

		return $repository->create( $name, $schema, 'published' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_REQUEST['action'], $_REQUEST['form_action'], $_REQUEST['form_id'], $_REQUEST['_wpnonce'] );
		remove_all_filters( 'wp_redirect' );

		parent::tearDown();
	}
}
