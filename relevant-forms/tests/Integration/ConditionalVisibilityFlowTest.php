<?php
/**
 * Conditional visibility submission flow: fields hidden by their rules are
 * skipped entirely (no required validation, no storage); revealed fields
 * are validated and stored normally.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Entries\EntryRepository;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRenderer;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Forms\SubmissionFeedback;
use RelevantForms\Http\SubmissionHandler;
use RelevantForms\Tests\Integration\Support\CapturesRedirects;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class ConditionalVisibilityFlowTest extends TestCase {

	use CapturesRedirects;

	/**
	 * Simulated POST data is part of the handler contract; reset it between tests.
	 */
	public function setUp(): void {
		parent::setUp();

		SubmissionFeedback::reset();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/relevant-demo/';
		$_SERVER['HTTP_REFERER']   = home_url( '/relevant-demo/' );
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
	 * The first field ignores its stored rules: it is always shown and
	 * stored, whatever the rules would decide.
	 */
	public function testTheFirstFieldIgnoresItsStoredRules(): void {
		$form = $this->makeFirstFieldConditionalForm();

		// The renderer drops the rules entirely: no payload for the
		// front-end script, no hidden marker on the field.
		$html = FormRenderer::renderForm( $form );

		$this->assertStringNotContainsString( 'data-rf-visibility', $html );
		$this->assertStringContainsString( 'relevant_field_f_plan', $html );

		// A value that its rules would hide is still stored.
		$this->submit( $form, 'free', '' );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'free', $entries[0]->meta['f_plan'] );
	}

	/**
	 * Run the front-end handler and capture the redirect target.
	 *
	 * @param Form   $form  Form.
	 * @param string $plan  Plan value.
	 * @param string $extra Extra value.
	 */
	private function submit( Form $form, string $plan, string $extra ): string {
		$_REQUEST['_wp_http_referer'] = '/relevant-demo/';

		return $this->captureRedirect( [ SubmissionHandler::class, 'maybeHandleFrontendSubmission' ], '', $this->payload( $form, $plan, $extra ) );
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
			'action'                 => 'relevant_forms_submit',
			'_relevant_form_id'      => $form->id,
			'_relevant_form_nonce'   => wp_create_nonce( 'relevant_form_' . $form->id ),
			'_wp_http_referer'       => '/relevant-demo/',
			'relevant_field_f_plan'  => $plan,
			'relevant_field_f_extra' => $extra,
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
	 * A conditional fixture whose FIRST field carries rules (imported shape).
	 */
	private function makeFirstFieldConditionalForm(): Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Conditional' )->withFields(
			[
				[
					'id'         => 'f_plan',
					'type'       => 'text',
					'label'      => 'Plan',
					'required'   => true,
					'visibility' => [
						'default' => 'hidden',
						'groups'  => [
							[
								[
									'field'    => 'f_extra',
									'operator' => 'is',
									'value'    => 'unlock',
								],
							],
						],
					],
				],
				[
					'id'    => 'f_extra',
					'type'  => 'text',
					'label' => 'Extra',
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
		remove_all_filters( 'relevant_forms_redirect' );
		unset( $_REQUEST['_wp_http_referer'] );

		parent::tearDown();
	}
}
