<?php
/**
 * Form editor priority-SMS integration tests: the checkbox + phone field
 * in the AI panel and the save gate that only accepts verified numbers.
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
final class FormEditorSmsTest extends TestCase {

	/**
	 * The editor shows the option active with the stored verified number.
	 */
	public function testTheEditorShowsTheStoredVerifiedNumber(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm(
			'SMS form',
			[
				'sms_alert' => true,
				'sms_phone' => '+393331234567',
			]
		);

		$_GET['form_id'] = $formId;

		$html = $this->renderEditor();

		$this->assertMatchesRegularExpression( '/name="ai_sms_alert"[^>]*checked/', $html );
		$this->assertMatchesRegularExpression( '/id="relevant-ai-sms-phone"[^>]*value="\+393331234567"/', $html );
		$this->assertStringContainsString( 'data-stored="+393331234567"', $html );
		$this->assertStringContainsString( 'rf-sms-send-code', $html );
		$this->assertStringContainsString( 'rf-sms-code-row', $html );
		$this->assertStringContainsString( 'rf-sms-code-message', $html );
		$this->assertStringContainsString( 'Verified', $html );

		// The verified state is shown in the message line below the field.
		$this->assertMatchesRegularExpression( '/class="rf-sms-message rf-sms-message--ok"[^>]*id="rf-sms-phone-message"/', $html );
		$this->assertMatchesRegularExpression( '/id="rf-sms-phone-message"[^>]*data-error="0"/', $html );

		// The field is active because the option is on.
		$this->assertDoesNotMatchRegularExpression( '/id="relevant-ai-sms-phone"[^>]*disabled/', $html );
	}

	/**
	 * A refused save shows its error in the phone field's message line.
	 */
	public function testTheSaveErrorAppearsBelowThePhoneField(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm( 'SMS form' );

		$_GET['form_id']   = $formId;
		$_GET['sms_error'] = 'unverified_phone';

		$html = $this->renderEditor();

		$this->assertMatchesRegularExpression( '/class="rf-sms-message rf-sms-message--error"[^>]*id="rf-sms-phone-message"/', $html );
		$this->assertMatchesRegularExpression( '/id="rf-sms-phone-message"[^>]*data-error="1"/', $html );
		$this->assertStringContainsString( 'The phone number was not saved', $html );

		// No duplicate page-level notice for the same error.
		$this->assertStringNotContainsString( 'notice notice-error', $html );
	}

	/**
	 * Without the option the phone field is inactive until it is checked.
	 */
	public function testThePhoneFieldIsInactiveUntilTheOptionIsChecked(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm( 'Plain form' );

		$_GET['form_id'] = $formId;

		$html = $this->renderEditor();

		$this->assertDoesNotMatchRegularExpression( '/name="ai_sms_alert"[^>]*checked/', $html );
		$this->assertMatchesRegularExpression( '/id="relevant-ai-sms-phone"[^>]*disabled/', $html );
		$this->assertMatchesRegularExpression( '/id="rf-sms-send-code"[^>]*disabled/', $html );
	}

	/**
	 * A new number that was not verified is not accepted: the save reports
	 * unverified_phone and the stored schema keeps its previous value.
	 */
	public function testAnUnverifiedNumberIsNotSaved(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm( 'SMS form' );

		$url = $this->save(
			$formId,
			[
				'ai_sms_alert' => '1',
				'ai_sms_phone' => '+39 333 999 9999',
			]
		);

		$this->assertStringContainsString( 'sms_error=unverified_phone', $url );

		$policy = ( new FormRepository() )->find( $formId )->schema->aiPolicy();
		$this->assertNotEmpty( $policy['sms_alert'] );
		$this->assertSame( '', (string) ( $policy['sms_phone'] ?? '' ) );
	}

	/**
	 * A number verified with a fresh code is saved in normalized form.
	 */
	public function testAFreshlyVerifiedNumberIsSaved(): void {
		$userId = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $userId );

		$formId = $this->makeForm( 'SMS form' );

		set_transient(
			'relevant_forms_sms_verified_' . $userId . '_' . md5( '+393331234567' ),
			'+393331234567',
			HOUR_IN_SECONDS
		);

		$url = $this->save(
			$formId,
			[
				'ai_sms_alert' => '1',
				'ai_sms_phone' => '+39 333 123 4567',
			]
		);

		$this->assertStringContainsString( 'saved=1', $url );
		$this->assertStringNotContainsString( 'sms_error=', $url );

		$policy = ( new FormRepository() )->find( $formId )->schema->aiPolicy();
		$this->assertSame( '+393331234567', (string) ( $policy['sms_phone'] ?? '' ) );
	}

	/**
	 * An unchanged number was verified before it was stored: no new code.
	 */
	public function testAnUnchangedStoredNumberIsKept(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm(
			'SMS form',
			[
				'sms_alert' => true,
				'sms_phone' => '+393331234567',
			]
		);

		$url = $this->save(
			$formId,
			[
				'ai_sms_alert' => '1',
				'ai_sms_phone' => '+39 333 123 4567',
			]
		);

		$this->assertStringContainsString( 'saved=1', $url );
		$this->assertStringNotContainsString( 'sms_error=', $url );

		$policy = ( new FormRepository() )->find( $formId )->schema->aiPolicy();
		$this->assertSame( '+393331234567', (string) ( $policy['sms_phone'] ?? '' ) );
	}

	/**
	 * Unchecking the option keeps the stored number (the field is disabled
	 * and not submitted) but turns the alerts off.
	 */
	public function testUncheckingKeepsTheStoredNumber(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm(
			'SMS form',
			[
				'sms_alert' => true,
				'sms_phone' => '+393331234567',
			]
		);

		$url = $this->save( $formId, [] );

		$this->assertStringContainsString( 'saved=1', $url );
		$this->assertStringNotContainsString( 'sms_error=', $url );

		$policy = ( new FormRepository() )->find( $formId )->schema->aiPolicy();
		$this->assertEmpty( $policy['sms_alert'] ?? null );
		$this->assertSame( '+393331234567', (string) ( $policy['sms_phone'] ?? '' ) );
	}

	/**
	 * Clearing the number while the option is on is accepted without a code.
	 */
	public function testClearingTheNumberIsAccepted(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$formId = $this->makeForm(
			'SMS form',
			[
				'sms_alert' => true,
				'sms_phone' => '+393331234567',
			]
		);

		$url = $this->save(
			$formId,
			[
				'ai_sms_alert' => '1',
				'ai_sms_phone' => '',
			]
		);

		$this->assertStringContainsString( 'saved=1', $url );
		$this->assertStringNotContainsString( 'sms_error=', $url );

		$policy = ( new FormRepository() )->find( $formId )->schema->aiPolicy();
		$this->assertSame( '', (string) ( $policy['sms_phone'] ?? '' ) );
	}

	/**
	 * Render the form editor and return its HTML.
	 */
	private function renderEditor(): string {
		ob_start();
		FormEditPage::render();

		return (string) ob_get_clean();
	}

	/**
	 * Submit the editor form with the given extra POST keys.
	 *
	 * @param int                  $formId Form id.
	 * @param array<string, mixed> $extra  Extra POST fields (ai_sms_*).
	 */
	private function save( int $formId, array $extra ): string {
		$_POST = array_merge(
			[
				'form_id'     => $formId,
				'form_name'   => 'SMS form',
				'form_status' => 'published',
			],
			$extra
		);

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_form_save' );

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
	 * Create a published form with an optional AI policy.
	 *
	 * @param string               $name   Form name.
	 * @param array<string, mixed> $policy AI policy overrides.
	 */
	private function makeForm( string $name, array $policy = [] ): int {
		$repository = new FormRepository();

		$schema = FormSchema::empty( $name )
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
			->withAiPolicy( $policy );

		return $repository->create( $name, $schema, 'published' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_GET['form_id'], $_GET['sms_error'], $_POST, $_REQUEST['_wpnonce'] );
		remove_all_filters( 'wp_redirect' );

		parent::tearDown();
	}
}
