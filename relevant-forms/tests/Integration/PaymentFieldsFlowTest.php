<?php
/**
 * Payment fields integration tests: editor save round-trip and rendering.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\FormSaveController;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Tests\Integration\Support\CapturesRedirects;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class PaymentFieldsFlowTest extends TestCase {

	use CapturesRedirects;

	/**
	 * Payment configuration posts through the editor controller and sticks.
	 */
	public function testSavingPersistsPaymentFieldConfiguration(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm();

		$_POST = [
			'form_id'         => $formId,
			'form_name'       => 'Payments',
			'form_status'     => 'published',
			'relevant_fields' => [
				'f_item'  => [
					'id'          => 'f_item',
					'type'        => 'payment-single',
					'label'       => 'Donation',
					'amount'      => '25.00',
					'user_amount' => '1',
					'min_amount'  => '5',
					'max_amount'  => '100',
				],
				'f_merch' => [
					'id'      => 'f_merch',
					'type'    => 'payment-select',
					'label'   => 'Merch',
					'choices' => "T-shirt|25.00\nCap|10",
				],
			],
		];

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_form_save' );

		$url = $this->handleAndCaptureRedirect();

		$this->assertStringContainsString( 'saved=1', $url );

		$fields = ( new FormRepository() )->find( $formId )->schema->fields();

		$this->assertSame( '25.00', $fields[0]['amount'] );
		$this->assertTrue( $fields[0]['user_amount'] );
		$this->assertSame( '5', $fields[0]['min_amount'] );
		$this->assertSame( '100', $fields[0]['max_amount'] );

		$this->assertSame( [ 'T-shirt|25.00', 'Cap|10' ], $fields[1]['choices'] );
	}

	/**
	 * Rendered payment fields expose positions, priced labels and the total.
	 */
	public function testRenderedPaymentFieldsCarryPositionsAndPrices(): void {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Payments' )->withFields(
			[
				[
					'id'      => 'f_merch',
					'type'    => 'payment-select',
					'label'   => 'Merch',
					'choices' => [ 'T-shirt|25.00', 'Cap|10' ],
				],
				[
					'id'    => 'f_total',
					'type'  => 'payment-total',
					'label' => 'Total',
				],
			]
		);

		$formId = $repository->create( 'Payments', $schema, 'published' );

		$html = do_shortcode( '[relevant_form id="' . $formId . '"]' );

		// Choices submit their POSITION, never the price.
		$this->assertStringContainsString( '<option value="0"', $html );
		$this->assertStringContainsString( 'T-shirt ($25.00)', $html );
		$this->assertStringContainsString( '<option value="1"', $html );
		$this->assertStringContainsString( 'Cap ($10.00)', $html );

		$this->assertStringContainsString( 'data-rf-payment-total', $html );
	}

	/**
	 * Run the save controller and capture the redirect target.
	 */
	private function handleAndCaptureRedirect(): string {
		return $this->captureRedirect( [ FormSaveController::class, 'handle' ] );
	}

	/**
	 * Create a published form with one field.
	 */
	private function makeForm(): int {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Payments' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
			]
		);

		return $repository->create( 'Payments', $schema, 'published' );
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
