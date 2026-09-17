<?php
/**
 * Form editor status consistency integration tests: the editor reflects the
 * real status of archived forms and saves preserve it.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\FormSaveController;
use RelevantForms\Admin\Pages\FormEditPage;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Tests\Integration\Support\RedirectedException;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FormEditorStatusTest extends TestCase {

	/**
	 * The status dropdown shows "Archived" selected for an archived form
	 * (matching the badge on the forms list).
	 */
	public function testEditorShowsTheArchivedStatus(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm( 'Old Landing' );
		( new FormRepository() )->archive( $formId );

		$_GET['form_id'] = $formId;

		ob_start();
		FormEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="archived"', $html );
		$this->assertMatchesRegularExpression( '/value="archived"\s+selected=/', $html );

		$this->assertSame( 1, preg_match( '/<select id="relevant-form-status"[^>]*>(.*?)<\/select>/s', $html, $matches ) );
		$this->assertSame( 1, substr_count( $matches[1], "selected='selected'" ) );
	}

	/**
	 * Saving an archived form keeps it archived (no silent fallback to draft).
	 */
	public function testSavingAnArchivedFormKeepsItArchived(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm( 'Old Landing' );
		( new FormRepository() )->archive( $formId );

		$_POST = [
			'form_id'     => $formId,
			'form_name'   => 'Old Landing',
			'form_status' => 'archived',
		];

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_form_save' );

		$url = $this->handleAndCaptureRedirect();

		$this->assertStringContainsString( 'saved=1', $url );
		$this->assertSame( 'archived', ( new FormRepository() )->find( $formId )->status );
	}

	/**
	 * Run the save controller and capture the redirect target.
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
			FormSaveController::handle();
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
		unset( $_GET['form_id'], $_REQUEST['_wpnonce'] );
		remove_all_filters( 'wp_redirect' );

		parent::tearDown();
	}
}
