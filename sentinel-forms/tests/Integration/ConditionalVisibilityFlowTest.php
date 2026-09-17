<?php
/**
 * Conditional visibility submission flow: fields hidden by their rules are
 * skipped entirely (no required validation, no storage); revealed fields
 * are validated and stored normally.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Tests\Integration;

use SentinelForms\Entries\EntryRepository;
use SentinelForms\Forms\Form;
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
final class ConditionalVisibilityFlowTest extends TestCase {

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
	 * A hidden field is neither validated nor stored.
	 */
	public function testHiddenFieldsSkipValidationAndStorage(): void {
		$form = $this->makeForm();

		$this->submit( $form, 'free', '' );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'free', $entries[0]->meta['f_plan'] );
		$this->assertArrayNotHasKey( 'f_extra', $entries[0]->meta );
	}

	/**
	 * Submitted values for hidden fields are ignored, even tampered ones.
	 */
	public function testSubmittedValuesForHiddenFieldsAreIgnored(): void {
		$form = $this->makeForm();

		$this->submit( $form, 'free', 'sneaky value' );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );

		$this->assertCount( 1, $entries );
		$this->assertArrayNotHasKey( 'f_extra', $entries[0]->meta );
	}

	/**
	 * A revealed required field is enforced like any other field.
	 */
	public function testRevealedRequiredFieldsAreEnforced(): void {
		$form = $this->makeForm();

		$_POST = $this->payload( $form, 'pro', '' );

		SubmissionHandler::maybeHandleFrontendSubmission();

		$errors = SubmissionFeedback::errors( (int) $form->id );

		$this->assertArrayHasKey( 'f_extra', $errors );
		$this->assertCount( 0, ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] ) );
	}

	/**
	 * A revealed field is validated and stored normally.
	 */
	public function testRevealedValuesAreStored(): void {
		$form = $this->makeForm();

		$this->submit( $form, 'pro', 'Here is extra' );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'Here is extra', $entries[0]->meta['f_extra'] );
	}

	/**
	 * Run the front-end handler and capture the redirect target.
	 *
	 * @param Form   $form  Form.
	 * @param string $plan  Plan value.
	 * @param string $extra Extra value.
	 */
	private function submit( Form $form, string $plan, string $extra ): string {
		$_POST                        = $this->payload( $form, $plan, $extra );
		$_REQUEST['_wp_http_referer'] = '/sentinel-demo/';

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
	 * Build the POST payload for one submission.
	 *
	 * @param Form   $form  Form.
	 * @param string $plan  Plan value.
	 * @param string $extra Extra value.
	 * @return array<string, mixed>
	 */
	private function payload( Form $form, string $plan, string $extra ): array {
		return [
			'action'                 => 'sentinel_forms_submit',
			'_sentinel_form_id'      => $form->id,
			'_sentinel_form_nonce'   => wp_create_nonce( 'sentinel_form_' . $form->id ),
			'_wp_http_referer'       => '/sentinel-demo/',
			'sentinel_field_f_plan'  => $plan,
			'sentinel_field_f_extra' => $extra,
		];
	}

	/**
	 * Create the conditional form fixture.
	 */
	private function makeForm(): Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Conditional' )->withFields(
			[
				[
					'id'      => 'f_plan',
					'type'    => 'select',
					'label'   => 'Plan',
					'choices' => [
						[
							'label' => 'Free',
							'value' => 'free',
						],
						[
							'label' => 'Pro',
							'value' => 'pro',
						],
					],
				],
				[
					'id'         => 'f_extra',
					'type'       => 'text',
					'label'      => 'Extra',
					'required'   => true,
					'visibility' => [
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
				],
			]
		);

		$id = $repository->create( 'Conditional', $schema, 'published' );

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
