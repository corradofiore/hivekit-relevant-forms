<?php
/**
 * Form editor visibility round-trip integration tests: posted rules are
 * normalized and persisted with the schema.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Admin\Controllers\FormSaveController;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormSchema;
use SentinelForms\Tests\Integration\Support\RedirectedException;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FormEditorVisibilityTest extends TestCase {

	/**
	 * Posted visibility rules round-trip through the controller in the
	 * normalized shape; junk groups are dropped.
	 */
	public function testSavingPersistsNormalizedVisibilityRules(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$_POST = [
			'form_id'         => $formId,
			'form_name'       => 'Contact',
			'form_status'     => 'published',
			'sentinel_fields' => [
				'f_plan'  => [
					'id'      => 'f_plan',
					'type'    => 'select',
					'label'   => 'Plan',
					'choices' => "Free\nPro",
				],
				'f_extra' => [
					'id'         => 'f_extra',
					'type'       => 'text',
					'label'      => 'Extra',
					'visibility' => [
						'default' => 'hidden',
						'groups'  => [
							[
								[
									'field'    => 'f_plan',
									'operator' => '==',
									'value'    => 'pro',
								],
							],
							[
								[
									'field'    => '',
									'operator' => 'is',
									'value'    => 'x',
								],
							],
						],
					],
				],
			],
		];

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'sentinel_form_save' );

		$url = $this->handleAndCaptureRedirect();

		$this->assertStringContainsString( 'saved=1', $url );

		$fields = ( new FormRepository() )->find( $formId )->schema->fields();

		// The legacy "==" spelling is canonicalized to "is" and the group
		// without a source field is dropped.
		$this->assertSame(
			[
				'default' => 'hidden',
				'groups'  => [
					[
						[
							'field'    => 'f_plan',
							'operator' => 'is',
							'value'    => 'pro',
						],
					],
				],
			],
			$fields[1]['visibility']
		);
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
	private function makeForm(): int {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Contact' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
			]
		);

		return $repository->create( 'Contact', $schema, 'published' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_REQUEST['_wpnonce'] );
		remove_all_filters( 'wp_redirect' );

		parent::tearDown();
	}
}
