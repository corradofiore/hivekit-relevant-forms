<?php
/**
 * Form row-action flow integration tests: nonce-signed links arrive as GET
 * on admin-post.php and perform duplicate/archive.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\FormActionController;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Tests\Integration\Support\CapturesRedirects;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FormActionFlowTest extends TestCase {

	use CapturesRedirects;

	/**
	 * The Duplicate row action creates a copy and redirects back to the list.
	 */
	public function testDuplicateRowActionCreatesACopy(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm( 'Emergency Request' );

		$this->signRequest( 'duplicate', $formId );

		$url = $this->handleAndCaptureRedirect();

		$this->assertStringContainsString( 'page=relevant-forms', $url );
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

		$this->assertStringContainsString( 'page=relevant-forms', $url );
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
			'action'      => 'relevant_forms_action',
			'form_action' => $action,
			'form_id'     => $formId,
			'_wpnonce'    => $nonce ?? wp_create_nonce( 'relevant_form_action_' . $action . '_' . $formId ),
		];
	}

	/**
	 * Run the controller and capture the redirect target.
	 */
	private function handleAndCaptureRedirect(): string {
		return $this->captureRedirect( [ FormActionController::class, 'handle' ] );
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
