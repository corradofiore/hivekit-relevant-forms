<?php
/**
 * WPForms importer (doc §47).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Import;

use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormSchema;
use SentinelForms\Support\Logger;

/**
 * Imports WPForms forms into the canonical Sentinel schema. Never
 * modifies or deletes the original WPForms records; imported forms are
 * created as drafts for review.
 */
final class WpFormsImporter {

	/**
	 * Option key for the last migration report.
	 */
	public const REPORT_OPTION = 'sentinel_forms_wpforms_migration_report';

	/**
	 * Repository (created lazily: schema building needs no database).
	 *
	 * @var FormRepository|null
	 */
	private ?FormRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param FormRepository|null $repository Form repository.
	 */
	public function __construct( ?FormRepository $repository = null ) {
		$this->repository = $repository;
	}

	/**
	 * Resolve the repository on demand.
	 */
	private function repository(): FormRepository {
		if ( null === $this->repository ) {
			$this->repository = new FormRepository();
		}

		return $this->repository;
	}

	/**
	 * Import one parsed form as a draft.
	 *
	 * @return array<string, mixed> Migration report row.
	 */
	public function import( WpFormsForm $form ): array {
		$schema  = $this->buildSchema( $form );
		$skipped = $form->skippedFields();

		$row = [
			'source_id'       => $form->sourceId,
			'title'           => $form->title,
			'status'          => 'imported',
			'form_id'         => 0,
			'imported_fields' => count( $form->mappedFields() ),
			'skipped'         => $skipped,
			'notes'           => $this->notes( $form ),
			'error'           => '',
		];

		if ( [] === $form->mappedFields() ) {
			$row['status'] = 'failed';
			$row['error']  = __( 'No importable fields were found.', 'sentinel-forms' );

			return $row;
		}

		try {
			$row['form_id'] = $this->repository()->create( $form->title, $schema, 'draft' );
		} catch ( \Throwable $exception ) {
			Logger::error(
				'WPForms import failed',
				[
					'source_id' => $form->sourceId,
					'exception' => $exception->getMessage(),
				]
			);

			$row['status'] = 'failed';
			$row['error']  = $exception->getMessage();
		}

		return $row;
	}

	/**
	 * Build the canonical schema for one WPForms form.
	 */
	public function buildSchema( WpFormsForm $form ): FormSchema {
		$fields = [];
		$used   = [];

		foreach ( $form->mappedFields() as $entry ) {
			$definition = $entry['definition'];

			$id = (string) $definition['id'];

			if ( '' === $id ) {
				$id = 'field_' . ( count( $fields ) + 1 );
			}

			if ( isset( $used[ $id ] ) ) {
				$id .= '_' . count( $fields );
			}

			$used[ $id ]      = true;
			$definition['id'] = $id;
			$fields[]         = $definition;
		}

		$notification = $form->notifications();
		$confirmation = $form->confirmation();

		return FormSchema::empty( $form->title )
			->withFields( $fields )
			->withNotifications( $notification['emails'] )
			->withConfirmation( $confirmation['message'], $confirmation['redirect'] )
			->withMetadata(
				[
					'imported_from'   => 'wpforms',
					'wpforms_form_id' => $form->sourceId,
					'imported_at'     => current_time( 'mysql', true ),
				]
			);
	}

	/**
	 * Per-form migration notes shown in the report.
	 *
	 * @return array<int, string>
	 */
	private function notes( WpFormsForm $form ): array {
		$notes = [];

		foreach ( $form->mappedFields() as $entry ) {
			foreach ( $entry['notes'] as $note ) {
				if ( ! in_array( $note, $notes, true ) ) {
					$notes[] = $note;
				}
			}
		}

		foreach ( $form->notifications()['notes'] as $note ) {
			if ( ! in_array( $note, $notes, true ) ) {
				$notes[] = $note;
			}
		}

		$notes[] = __( 'Imported as a draft: review and publish when ready. Original WPForms data is untouched.', 'sentinel-forms' );

		return $notes;
	}

	/**
	 * Persist the latest migration report for the admin UI.
	 *
	 * @param array<string, mixed> $report Report payload.
	 */
	public static function rememberReport( array $report ): void {
		update_option( self::REPORT_OPTION, $report );
	}

	/**
	 * Latest migration report, if any.
	 *
	 * @return array<string, mixed>
	 */
	public static function lastReport(): array {
		$report = get_option( self::REPORT_OPTION, [] );

		return is_array( $report ) ? $report : [];
	}
}
