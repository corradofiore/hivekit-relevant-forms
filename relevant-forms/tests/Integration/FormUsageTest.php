<?php
/**
 * Form usage discovery + "Used on" column integration tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Admin\Pages\FormListPage;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Forms\FormUsage;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class FormUsageTest extends TestCase {

	/**
	 * Bypass the transient cache so assertions see fresh data.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'relevant_forms_form_usage_cache_ttl', '__return_zero' );
	}

	/**
	 * Forms map to every page/post embedding their shortcode (any status,
	 * block markup included), sorted by title.
	 */
	public function testMapsFormsToTheEmbeddingContent(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$pageA = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'Contact',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:shortcode -->[relevant_form id="7"]<!-- /wp:shortcode -->',
			]
		);
		$pageB = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'Support',
				'post_status'  => 'draft',
				'post_content' => "Intro\n[relevant_form id='7'] and [relevant_form id=9]",
			]
		);
		self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'Unrelated',
				'post_status'  => 'publish',
				'post_content' => 'No shortcode here.',
			]
		);

		$usage = FormUsage::map();

		$this->assertArrayHasKey( 7, $usage );
		$this->assertSame( [ 'Contact', 'Support' ], array_column( $usage[7], 'title' ) );
		$this->assertSame( $pageA, $usage[7][0]['id'] );
		$this->assertSame( '', $usage[7][0]['status_label'] );
		$this->assertSame( 'Draft', $usage[7][1]['status_label'] );
		$this->assertStringContainsString( 'post=' . $pageA, $usage[7][0]['edit_url'] );

		$this->assertArrayHasKey( 9, $usage );
		$this->assertCount( 1, $usage[9] );
		$this->assertSame( $pageB, $usage[9][0]['id'] );
	}

	/**
	 * Shortcode parsing tolerates quoting/attribute variants, collapses
	 * repeats and ignores decoys.
	 */
	public function testExtractHandlesShortcodeVariantsAndDecoys(): void {
		$this->assertSame( [ 7 ], FormUsage::extract( '[relevant_form id="7"]' ) );
		$this->assertSame( [ 7 ], FormUsage::extract( "[relevant_form id='7']" ) );
		$this->assertSame( [ 7 ], FormUsage::extract( '[relevant_form  id = "7" ]' ) );
		$this->assertSame( [ 7, 9 ], FormUsage::extract( '[relevant_form id="7"][relevant_form id="9"]' ) );
		$this->assertSame( [ 7 ], FormUsage::extract( '[relevant_form id="7"] [relevant_form id="7"]' ) );
		$this->assertSame( [], FormUsage::extract( '[other_form id="7"]' ) );
		$this->assertSame( [], FormUsage::extract( '[relevant_form]' ) );
		$this->assertSame( [], FormUsage::extract( '[relevant_form id="0"]' ) );
		$this->assertSame( [], FormUsage::extract( 'no shortcodes' ) );
	}

	/**
	 * forForm() returns one form's pages only; unknown ids are empty.
	 */
	public function testForFormReturnsOnlyThatFormsPages(): void {
		$page = self::factory()->post->create( [ 'post_content' => '[relevant_form id="21"]' ] );
		self::factory()->post->create( [ 'post_content' => '[relevant_form id="22"]' ] );

		$usage = FormUsage::forForm( 21 );

		$this->assertCount( 1, $usage );
		$this->assertSame( $page, $usage[0]['id'] );
		$this->assertSame( [], FormUsage::forForm( 999 ) );
	}

	/**
	 * The Forms list renders the "Used on" column, links the single page and
	 * offers "View all" with a popover when embedded in several places.
	 */
	public function testFormsListShowsUsedOnColumnWithViewAll(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$multi  = $this->makeForm( 'Multi embed' );
		$single = $this->makeForm( 'Single embed' );

		self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'Contact',
				'post_content' => '[relevant_form id="' . $multi . '"]',
			]
		);
		self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'About',
				'post_content' => '[relevant_form id="' . $multi . '"]',
			]
		);
		self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'Solo',
				'post_content' => '[relevant_form id="' . $single . '"]',
			]
		);

		ob_start();
		FormListPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Used on', $html );
		$this->assertStringContainsString( 'Contact', $html );
		$this->assertStringContainsString( '+1 more', $html );
		$this->assertStringContainsString( 'id="relevant-forms-usage-' . $multi . '"', $html );
		$this->assertStringContainsString( 'Solo', $html );

		// The header hint opens a card explainer documenting the limitation.
		$this->assertStringContainsString( 'relevant-forms-used-on__hint', $html );
		$this->assertStringContainsString( 'About the &quot;Used on&quot; column', $html );
		$this->assertStringContainsString( 'relevant_forms_render() are not listed', $html );

		// View all is a fragment link that opens the core card panel on click.
		$this->assertStringContainsString( 'href="#relevant-forms-usage-' . $multi . '"', $html );
		$this->assertStringContainsString( 'class="card relevant-forms-used-on__popover"', $html );

		// Only the multi-embed form gets the "View all" toggle.
		$this->assertSame( 1, substr_count( $html, 'View all' ) );
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
		remove_filter( 'relevant_forms_form_usage_cache_ttl', '__return_zero' );

		parent::tearDown();
	}
}
