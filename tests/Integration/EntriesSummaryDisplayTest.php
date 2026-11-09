<?php
/**
 * Entries admin surfaces (doc §24): the short summary the SMS alert carries,
 * shown in the entries list column and in the entry view.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Pages\EntryListPage;
use RelevantForms\Admin\Pages\EntryViewPage;
use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class EntriesSummaryDisplayTest extends TestCase {

	/**
	 * Sign in as an administrator.
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * A classified entry shows the short summary in its own column, in place of
	 * the longer email phrase, and an entry classified before the Cloud returned
	 * two summaries still shows something (the column must agree with the alert).
	 */
	public function testTheListShowsTheSmsSummaryInsteadOfTheEmailPhrase(): void {
		$formId = $this->form();

		$classified = $this->entry(
			$formId,
			[
				'f_name'          => 'Ada',
				'_classification' => [
					'summary'     => 'Burst pipe in the basement',
					'sms_summary' => 'Burst pipe basement',
				],
			]
		);
		$legacy     = $this->entry(
			$formId,
			[
				'f_name'          => 'Grace',
				'_classification' => [ 'summary' => 'Quote request for the loft' ],
			]
		);

		ob_start();
		EntryListPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'SMS summary', $html );

		$row = $this->row( $html, $classified );
		$this->assertStringContainsString( 'Burst pipe basement', $row );
		$this->assertStringNotContainsString( 'Burst pipe in the basement', $row );

		// One phrase only: it is what the text message would send, so it shows.
		$this->assertStringContainsString( 'Quote request for the loft', $this->row( $html, $legacy ) );
	}

	/**
	 * Without a classification the column stays empty rather than guessing from
	 * the submitted values.
	 */
	public function testTheColumnIsEmptyForUnclassifiedEntries(): void {
		$formId = $this->form();

		$plain = $this->entry( $formId, [ 'f_name' => 'Linus' ] );

		ob_start();
		EntryListPage::render();
		$html = (string) ob_get_clean();

		$row = $this->row( $html, $plain );

		$this->assertStringContainsString( 'Linus', $row );
		$this->assertStringContainsString( '—', $row );
	}

	/**
	 * The entry view names both phrases, so the screen that shows the whole
	 * classification cannot be read as if the email phrase were the SMS one.
	 */
	public function testTheEntryViewNamesBothSummaries(): void {
		$formId = $this->form();

		$entryId = $this->entry(
			$formId,
			[
				'f_name'          => 'Ada',
				'_classification' => [
					'summary'     => 'Burst pipe in the basement',
					'sms_summary' => 'Burst pipe basement',
				],
			]
		);

		$_GET['entry_id'] = $entryId;

		ob_start();
		EntryViewPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<th scope="row">Summary</th><td>Burst pipe in the basement', $html );
		$this->assertStringContainsString( '<th scope="row">SMS summary</th><td>Burst pipe basement</td>', $html );
	}

	/**
	 * The rendered table row for one entry.
	 *
	 * @param string $html    Rendered page.
	 * @param int    $entryId Entry ID.
	 */
	private function row( string $html, int $entryId ): string {
		$found = (int) preg_match(
			'/<tr>(?:(?!<\/tr>).)*entry_id=' . $entryId . '(?:(?!<\/tr>).)*<\/tr>/s',
			$html,
			$matches
		);

		$this->assertSame( 1, $found, 'No table row rendered for entry ' . $entryId );

		return $matches[0];
	}

	/**
	 * Create a published form with one field.
	 */
	private function form(): int {
		$schema = FormSchema::empty( 'Contact' )->withFields(
			[
				[
					'id'    => 'f_name',
					'type'  => 'text',
					'label' => 'Name',
				],
			]
		);

		return ( new FormRepository() )->create( 'Contact', $schema, 'published' );
	}

	/**
	 * Store an entry with the given meta.
	 *
	 * @param int                  $formId Form ID.
	 * @param array<string, mixed> $meta   Field values and classification.
	 */
	private function entry( int $formId, array $meta ): int {
		$forms = new FormRepository();
		$form  = $forms->find( $formId );

		return ( new EntryRepository() )->create(
			new Entry(
				null,
				$formId,
				$forms->createVersion( $formId, $form->schema ),
				Entry::STATUS_NEW,
				'https://example.test/contact',
				'',
				'ua-hash',
				'ip-hash',
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				$meta
			)
		);
	}
}
