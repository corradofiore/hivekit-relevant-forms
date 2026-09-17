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
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Http\SubmissionHandler;
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
	 * Build a published three-field form.
	 */
	private function makeForm(): Form {
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
