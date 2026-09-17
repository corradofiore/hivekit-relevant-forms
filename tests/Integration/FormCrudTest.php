<?php
/**
 * Form CRUD integration tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Entries\EntryRepository;
use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;

/**
 * Form create/read/update/archive integration tests.
 *
 * @group integration
 */
final class FormCrudTest extends TestCase {

	/**
	 * Test create, find, publish and archive.
	 */
	public function testFormLifecycle(): void {
		$repository = new FormRepository();

		$schema = FormSchema::empty( 'Contact' )->withFields(
			[
				[
					'id'    => 'f_1',
					'type'  => 'text',
					'label' => 'Name',
				],
			]
		);

		$id = $repository->create( 'Contact', $schema );

		$this->assertGreaterThan( 0, $id );

		$form = $repository->find( $id );
		$this->assertNotNull( $form );
		$this->assertSame( 'Contact', $form->name );
		$this->assertSame( 'draft', $form->status );
		$this->assertCount( 1, $form->schema->fields() );

		// Drafts are not renderable.
		$this->assertNull( $repository->findPublished( $id ) );

		$repository->update( $id, 'Contact', $schema, 'published' );
		$this->assertNotNull( $repository->findPublished( $id ) );

		$repository->archive( $id );
		$this->assertSame( 'archived', $repository->find( $id )->status );
		$this->assertNull( $repository->findPublished( $id ) );
	}

	/**
	 * Test duplication creates a draft copy.
	 */
	public function testDuplicateForm(): void {
		$repository = new FormRepository();

		$id   = $repository->create( 'Original', FormSchema::empty( 'Original' ) );
		$copy = $repository->duplicate( $id );

		$this->assertNotSame( $id, $copy );
		$this->assertSame( 'Original (copy)', $repository->find( $copy )->name );
		$this->assertSame( 'draft', $repository->find( $copy )->status );
	}

	/**
	 * Test version snapshots increment.
	 */
	public function testVersionSnapshot(): void {
		$repository = new FormRepository();

		$id       = $repository->create( 'Versioned', FormSchema::empty( 'Versioned' ) );
		$schema   = FormSchema::empty( 'Versioned' );
		$version1 = $repository->createVersion( $id, $schema );
		$version2 = $repository->createVersion( $id, $schema );

		$this->assertGreaterThan( 0, $version1 );
		$this->assertGreaterThan( $version1, $version2 );
	}

	/**
	 * Unfiltered counts must run without wpdb "doing it wrong" notices
	 * (prepare() requires at least one placeholder).
	 */
	public function testUnfilteredCountsRunWithoutDoingItWrongNotices(): void {
		$violations = [];

		add_action(
			'doing_it_wrong_run',
			static function ( string $function_name, string $message ) use ( &$violations ): void {
				$violations[] = $function_name . ': ' . $message;
			},
			10,
			3
		);

		( new FormRepository() )->count();
		( new EntryRepository() )->count();

		$this->assertSame( [], $violations );
	}
}
