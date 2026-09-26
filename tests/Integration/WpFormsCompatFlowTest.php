<?php
/**
 * WPForms hook compatibility integration tests (Phase 7.5).
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
use RelevantForms\Http\SubmissionHandler;
use RelevantForms\Import\WpFormsScanner;
use RelevantForms\Tests\Integration\Support\RedirectedException;
use RelevantForms\Tests\Integration\Support\StoppedException;

/**
 * Automator/Newsletter-style consumers receive WPForms-shaped hooks.
 *
 * @group integration
 */
final class WpFormsCompatFlowTest extends TestCase {

	/**
	 * `wpforms_process_complete` + form-id variant fire with WPForms shapes.
	 */
	public function testProcessCompleteFiresWithWpformsShapes(): void {
		$form = $this->makeForm();

		$complete = [];
		$variant  = [];

		add_action(
			'wpforms_process_complete',
			static function ( $fields, $entry, $form_data, $entry_id ) use ( &$complete ): void {
				$complete[] = [
					'fields'    => $fields,
					'entry'     => $entry,
					'form_data' => $form_data,
					'entry_id'  => $entry_id,
				];
			},
			10,
			4
		);
		add_action(
			'wpforms_process_complete_' . $form->id,
			static function ( $fields, $entry, $form_data, $entry_id ) use ( &$variant ): void {
				$variant[] = [
					'fields'    => $fields,
					'entry'     => $entry,
					'form_data' => $form_data,
					'entry_id'  => $entry_id,
				];
			},
			10,
			4
		);

		$this->submit( $form->id, $this->fields() );

		$this->assertCount( 1, $complete );
		$this->assertCount( 1, $variant );

		$captured = $complete[0];

		$this->assertSame( 'Ada', $captured['fields']['f_name']['value'] );
		$this->assertSame( 'Name', $captured['fields']['f_name']['name'] );
		$this->assertSame( 'text', $captured['fields']['f_name']['type'] );
		$this->assertSame( 'email', $captured['fields']['f_email']['type'] );
		$this->assertSame( 'textarea', $captured['fields']['f_msg']['type'] );

		$this->assertSame( $form->id, $captured['form_data']['id'] );
		$this->assertArrayHasKey( 'relevant_field_f_name', $captured['entry'] );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertCount( 1, $entries );
		$this->assertSame( (int) $entries[0]->id, $captured['entry_id'] );
		$this->assertSame( $captured['entry_id'], $variant[0]['entry_id'] );
	}

	/**
	 * Consumers can halt a submission through wpforms()->process->errors.
	 */
	public function testProcessGateHaltsSubmissionsLikeWpforms(): void {
		$form = $this->makeForm();

		add_action(
			'wpforms_process',
			static function ( $fields, $entry, $form_data ): void {
				unset( $entry, $form_data );

				$message = (string) ( $fields['f_msg']['value'] ?? '' );

				if ( str_contains( $message, 'blocked spam' ) ) {
					$errors          = wpforms()->process->errors;
					$errors['f_msg'] = 'Blocked by consumer.';
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride -- test consumer mutates the shared gate by design.
					wpforms()->process->errors = $errors;
				}
			},
			10,
			3
		);

		$blocked = $this->submit(
			$form->id,
			[
				'relevant_field_f_name'  => 'Bot',
				'relevant_field_f_email' => 'bot@example.com',
				'relevant_field_f_msg'   => 'This is blocked spam content.',
			]
		);

		$this->assertSame( 422, $blocked['status'] ?? 0 );
		$this->assertStringContainsString( 'Blocked by consumer.', (string) ( $blocked['body'] ?? '' ) );
		$this->assertSame( [], ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] ) );

		// The gate resets: a clean submission goes through afterwards.
		$this->submit( $form->id, $this->fields() );
		$this->assertCount( 1, ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] ) );
	}

	/**
	 * wpforms()->entry->get() returns WPForms-shaped entry data.
	 */
	public function testEntryAccessorReturnsWpformsShapedEntries(): void {
		$form = $this->makeForm();

		$this->submit( $form->id, $this->fields() );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$entry   = wpforms()->entry->get( (int) $entries[0]->id );

		$this->assertNotNull( $entry );
		$this->assertSame( (int) $entries[0]->id, $entry->entry_id );
		$this->assertSame( $form->id, $entry->form_id );

		$fields = json_decode( (string) $entry->fields, true );
		$this->assertSame( 'Ada', $fields['f_name']['value'] );

		$this->assertNull( wpforms()->entry->get( 999999 ) );
	}

	/**
	 * Imported forms emit the original WPForms form ID so addon mappings
	 * stored against it (e.g. Newsletter sources) keep matching.
	 */
	public function testProcessCompleteUsesOriginalWpformsIdForImportedForms(): void {
		$form = $this->makeForm( [ 'wpforms_form_id' => '987654' ] );
		$this->assertNotSame( 987654, $form->id );

		$complete  = [];
		$bySource  = [];
		$byNative  = [];
		$listeners = static function ( array &$sink ): callable {
			return static function ( $fields, $entry, $form_data, $entry_id ) use ( &$sink ): void {
				unset( $entry, $entry_id );

				$sink[] = [
					'fields'    => $fields,
					'form_data' => $form_data,
				];
			};
		};

		add_action( 'wpforms_process_complete', $listeners( $complete ), 10, 4 );
		add_action( 'wpforms_process_complete_987654', $listeners( $bySource ), 10, 4 );
		add_action( 'wpforms_process_complete_' . $form->id, $listeners( $byNative ), 10, 4 );

		$this->submit( $form->id, $this->fields() );

		$this->assertCount( 1, $complete );
		$this->assertSame( 987654, $complete[0]['form_data']['id'] );
		$this->assertSame( 'Ada', $complete[0]['fields']['f_name']['value'] );
		$this->assertCount( 1, $bySource );
		$this->assertCount( 0, $byNative );
	}

	/**
	 * Provider shim: bootstrap checks pass and original WPForms records
	 * parse into WPForms-shaped field lists for mapping UIs.
	 */
	public function testProviderShimParsesOriginalWpformsRecords(): void {
		$this->assertTrue( class_exists( 'WPForms' ) );
		$this->assertTrue( class_exists( 'WPForms_Provider' ) );

		$postId = wp_insert_post(
			[
				'post_type'    => 'wpforms',
				'post_status'  => 'publish',
				'post_title'   => 'Newsletter source',
				'post_content' => (string) wp_json_encode(
					[
						'fields' => [
							'0' => [
								'id'     => 0,
								'type'   => 'name',
								'label'  => 'Name',
								'format' => 'first-last',
							],
							'1' => [
								'id'    => 1,
								'type'  => 'email',
								'label' => 'Email',
							],
						],
					]
				),
			]
		);

		$provider = new \WPForms_Provider();
		$fields   = $provider->get_form_fields( get_post( $postId ) );

		$this->assertCount( 2, $fields );
		$this->assertSame( 'name', $fields[0]['type'] );
		$this->assertSame( 'first-last', $fields[0]['format'] );
		$this->assertSame( 'Email', $fields[1]['label'] );

		// Post IDs are accepted too; unknown records yield an empty list.
		$this->assertCount( 2, $provider->get_form_fields( $postId ) );
		$this->assertSame( [], $provider->get_form_fields( 999999 ) );
	}

	/**
	 * The shims must not confuse WPForms detection: the compat layer runs
	 * precisely because the real plugin is absent (the E2E regression was
	 * a shim-loaded `wpforms()` function flipping wpFormsActive() to true).
	 */
	public function testShimsDoNotPolluteWpformsDetection(): void {
		$this->assertTrue( class_exists( 'WPForms' ) );
		$this->assertTrue( function_exists( 'wpforms' ) );
		$this->assertFalse( WpFormsScanner::wpFormsActive() );
	}

	/**
	 * Rendering bridges to `wpforms_frontend_output` for display addons.
	 */
	public function testFrontendOutputFiresForDisplayConsumers(): void {
		$form = $this->makeForm( [ 'wpforms_form_id' => 987654 ] );

		$captured = [];

		add_action(
			'wpforms_frontend_output',
			static function ( $form_data, $passed ) use ( &$captured ): void {
				$captured[] = [ $form_data, $passed ];
			},
			10,
			2
		);

		$this->assertNotSame( '', FormRenderer::renderForm( $form ) );

		$this->assertCount( 1, $captured );
		$this->assertSame( 987654, $captured[0][0]['id'] );
		$this->assertSame( $form, $captured[0][1] );
	}

	/**
	 * Build a published three-field form.
	 *
	 * @param array<string, mixed> $metadata Schema metadata.
	 */
	private function makeForm( array $metadata = [] ): Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Contact' )->withFields(
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
					'id'       => 'f_msg',
					'type'     => 'textarea',
					'label'    => 'Message',
					'required' => true,
				],
			]
		);

		if ( [] !== $metadata ) {
			$schema = $schema->withMetadata( $metadata );
		}

		$id = $repository->create( 'Contact', $schema, 'published' );

		return $repository->find( $id );
	}

	/**
	 * Standard submission fields.
	 *
	 * @return array<string, string>
	 */
	private function fields(): array {
		return [
			'relevant_field_f_name'  => 'Ada',
			'relevant_field_f_email' => 'ada@example.com',
			'relevant_field_f_msg'   => 'Please send a quote.',
		];
	}

	/**
	 * Simulate a POST and capture the outcome.
	 *
	 * @param int                  $formId Form ID.
	 * @param array<string, mixed> $fields Field values.
	 * @return array{url?: string, status?: int, body?: string}
	 */
	private function submit( int $formId, array $fields ): array {
		$_POST = array_merge(
			[
				'_relevant_form_id'    => $formId,
				'_relevant_form_nonce' => wp_create_nonce( 'relevant_form_' . $formId ),
				'_relevant_started_at' => (string) ( microtime( true ) - 30.0 ),
			],
			$fields
		);

		add_filter(
			'relevant_forms_redirect',
			static function ( string $url ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
				throw new RedirectedException( $url );
			}
		);

		add_filter(
			'relevant_forms_invalid_submission_response',
			static function ( string $body, int $status ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated body, assertions only.
				throw new StoppedException( $status, $body );
			},
			10,
			2
		);

		try {
			SubmissionHandler::handle();
		} catch ( RedirectedException $redirected ) {
			return [ 'url' => $redirected->url ];
		} catch ( StoppedException $stopped ) {
			return [
				'status' => $stopped->status,
				'body'   => $stopped->body,
			];
		} finally {
			remove_all_filters( 'relevant_forms_redirect' );
			remove_all_filters( 'relevant_forms_invalid_submission_response' );
		}

		return [];
	}
}
