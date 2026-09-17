<?php
/**
 * Admin correction + preference profile integration tests (doc §26–27).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Controllers\EntryCorrectionController;
use RelevantForms\Ai\PreferenceProfile;
use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Events\EventRecorder;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Http\SubmissionHandler;
use RelevantForms\Tests\Integration\Support\RedirectedException;
use RelevantForms\Tests\Integration\Support\StoppedException;

/**
 * Corrections update the entry, feed the profile and improve future
 * classification context.
 *
 * @group integration
 */
final class EntryCorrectionFlowTest extends TestCase {

	/**
	 * Classification reason used by the fake Cloud.
	 */
	private const REASON = 'seo_solicitation';

	/**
	 * Requests captured by the fake Cloud.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $requests = [];

	/**
	 * Run as an administrator and install the fake Cloud endpoint.
	 */
	public function setUp(): void {
		parent::setUp();

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$this->requests = [];

		update_option(
			'relevant_forms_settings',
			[
				'cloud_url'       => 'https://cloud.example.com',
				'cloud_api_key'   => 'site-key',
				'ai_site_purpose' => 'Plumbing company',
			]
		);
		delete_option( PreferenceProfile::OPTION );

		$connection = new CloudConnection();
		$connection->connect( 'site-uuid-1', 'https://a.example', 'Acme' );
		$connection->enableCapability( CloudConnection::CAP_AI );

		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array {
				unset( $pre );
				$this->requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				return [
					'response' => [ 'code' => 200 ],
					'body'     => (string) wp_json_encode(
						[
							'success' => true,
							'data'    => [
								'classification' => [
									'spam_probability' => 0.02,
									'relevance'        => 0.5,
									'urgency'          => 0.3,
									'summary'          => 'Guest post offer.',
									'reason_code'      => self::REASON,
								],
							],
						]
					),
				];
			},
			10,
			3
		);
	}

	/**
	 * A correction updates meta, status, events and the profile.
	 */
	public function testCorrectionUpdatesEntryAndProfile(): void {
		$form  = $this->makeForm();
		$entry = $this->submitAndFindEntry( $form );

		$url = $this->correct( (int) $entry->id, 'spam' );

		$this->assertStringContainsString( 'corrected=1', $url );

		$updated = ( new EntryRepository() )->find( (int) $entry->id );
		$this->assertSame( Entry::STATUS_SPAM, $updated->status );

		$correction = $updated->meta['_classification_correction'] ?? [];
		$this->assertSame( 'spam', $correction['verdict'] ?? '' );
		$this->assertSame( self::REASON, $correction['original']['reason_code'] ?? '' );
		$this->assertSame( get_current_user_id(), $correction['user_id'] ?? 0 );

		$names = array_column( EventRecorder::forEntry( (int) $entry->id ), 'event' );
		$this->assertContains( 'classification_corrected', $names );

		$stored = get_option( PreferenceProfile::OPTION );
		$this->assertSame( 1, $stored['tallies'][ self::REASON ]['spam'] ?? 0 );
	}

	/**
	 * Feedback improves the context of the next classification (doc §27).
	 */
	public function testProfileGuidanceFlowsIntoTheNextClassification(): void {
		$form = $this->makeForm();

		// Two identical corrections establish the "usually spam" signal.
		foreach ( [ 1, 2 ] as $ignored ) {
			$entry = $this->submitAndFindEntry( $form );
			$this->correct( (int) $entry->id, 'spam' );
		}

		$this->requests = [];

		$this->submit( $form->id, $this->fields() );

		$this->assertCount( 1, $this->requests );

		$body = (string) $this->requests[0]['args']['body'];
		$this->assertStringContainsString( '"usually_spam"', $body );
		$this->assertStringContainsString( self::REASON, $body );
		$this->assertStringContainsString( '"recent_corrections"', $body );
	}

	/**
	 * Entries without a classification cannot be corrected.
	 */
	public function testCorrectionRequiresAStoredClassification(): void {
		$repository = new EntryRepository();
		$form       = $this->makeForm();

		$entry   = new Entry(
			null,
			$form->id,
			( new FormRepository() )->createVersion( $form->id, $form->schema ),
			Entry::STATUS_NEW,
			'https://a.example',
			'',
			'',
			'',
			current_time( 'mysql', true ),
			current_time( 'mysql', true ),
			[ 'f_msg' => 'No classification here.' ]
		);
		$entryId = $repository->create( $entry );

		$this->expectException( \WPDieException::class );

		$this->correct( $entryId, 'legitimate' );
	}

	/**
	 * Submit fields and return the newest stored entry.
	 */
	private function submitAndFindEntry( Form $form ): Entry {
		$this->submit( $form->id, $this->fields() );

		$entries = ( new EntryRepository() )->findMany( [ 'form_id' => $form->id ] );
		$this->assertNotEmpty( $entries );

		// Entries may share a second-resolution timestamp; newest = highest id.
		usort( $entries, static fn ( Entry $a, Entry $b ): int => (int) $b->id <=> (int) $a->id );

		return $entries[0];
	}

	/**
	 * Build a published form with AI classification enabled.
	 */
	private function makeForm(): Form {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Enquiry' )
			->withFields(
				[
					[
						'id'       => 'f_msg',
						'type'     => 'textarea',
						'label'    => 'Message',
						'required' => true,
					],
				]
			)
			->withAiPolicy( [ 'enabled' => true ] );

		$id = $repository->create( 'Enquiry', $schema, 'published' );

		return $repository->find( $id );
	}

	/**
	 * Standard submission fields.
	 *
	 * @return array<string, string>
	 */
	private function fields(): array {
		return [ 'relevant_field_f_msg' => 'Hello from a real visitor.' ];
	}

	/**
	 * Simulate the correction admin action with a nonce.
	 *
	 * @param int    $entryId Entry ID.
	 * @param string $verdict Verdict.
	 */
	private function correct( int $entryId, string $verdict ): string {
		$_POST                = [
			'entry_id' => $entryId,
			'verdict'  => $verdict,
		];
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'relevant_entry_correction_' . $entryId );

		add_filter(
			'relevant_forms_redirect',
			static function ( string $url ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
				throw new RedirectedException( $url );
			}
		);

		try {
			EntryCorrectionController::handle();
		} catch ( RedirectedException $redirected ) {
			return $redirected->url;
		} finally {
			remove_all_filters( 'relevant_forms_redirect' );
			unset( $_REQUEST['_wpnonce'] );
		}

		return '';
	}

	/**
	 * Simulate a POST at human speed.
	 *
	 * @param int                  $formId Form ID.
	 * @param array<string, mixed> $fields Field values.
	 */
	private function submit( int $formId, array $fields ): void {
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
		} catch ( RedirectedException | StoppedException $stopped ) {
			unset( $stopped );
		} finally {
			remove_all_filters( 'relevant_forms_redirect' );
			remove_all_filters( 'relevant_forms_invalid_submission_response' );
		}
	}

	/**
	 * Tear down filters and request state.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		unset( $_REQUEST['_wpnonce'] );
		parent::tearDown();
	}
}
