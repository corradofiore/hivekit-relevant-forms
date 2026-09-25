<?php
/**
 * WPForms importer (doc §47).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Import;

use RelevantForms\Forms\FormRepository;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Payments\Money;
use RelevantForms\Support\Logger;

/**
 * Imports WPForms forms into the canonical Relevant schema. Never
 * modifies or deletes the original WPForms records; imported forms are
 * created as drafts for review.
 */
final class WpFormsImporter {

	/**
	 * Option key for the last migration report.
	 */
	public const REPORT_OPTION = 'relevant_forms_wpforms_migration_report';

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
		$allowRawCode = current_user_can( 'unfiltered_html' );
		$schema       = $this->buildSchema( $form, $allowRawCode );
		$skipped      = $form->skippedFields();

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
			$row['error']  = __( 'No importable fields were found.', 'relevant-forms' );

			return $row;
		}

		if ( ! $allowRawCode && self::hasCustomCodeFields( $form ) ) {
			$row['notes'][] = __( 'Custom HTML fields were sanitized: your user cannot save unfiltered HTML.', 'relevant-forms' );
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
	 *
	 * Raw custom-code blocks are only stored verbatim when the importing user
	 * may save unfiltered HTML; otherwise they are sanitized, mirroring the
	 * editor save behaviour.
	 *
	 * @param WpFormsForm $form           Parsed source form.
	 * @param bool        $allow_raw_code Whether raw HTML code can be stored.
	 */
	public function buildSchema( WpFormsForm $form, bool $allow_raw_code = true ): FormSchema {
		$fields = [];
		$used   = [];

		foreach ( $form->mappedFields() as $entry ) {
			$definition = $entry['definition'];

			if ( ! $allow_raw_code && 'html' === (string) ( $definition['type'] ?? '' ) ) {
				$definition['code'] = wp_kses_post( (string) ( $definition['code'] ?? '' ) );
			}

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

		$schema = FormSchema::empty( $form->title )
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

		if ( self::hasPaymentFields( $form ) ) {
			$schema = $schema->withCurrency( self::wpFormsCurrency() );
		}

		return $schema;
	}

	/**
	 * Whether the mapped fields contain non-empty custom-code blocks.
	 */
	private static function hasCustomCodeFields( WpFormsForm $form ): bool {
		foreach ( $form->mappedFields() as $entry ) {
			if ( 'html' === (string) ( $entry['definition']['type'] ?? '' ) && '' !== trim( (string) ( $entry['definition']['code'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the mapped fields contain payment types.
	 */
	private static function hasPaymentFields( WpFormsForm $form ): bool {
		foreach ( $form->mappedFields() as $entry ) {
			if ( str_starts_with( (string) ( $entry['definition']['type'] ?? '' ), 'payment-' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * WPForms' account-wide currency setting (their payment fields store bare
	 * amounts). Falls back to the Relevant default when WPForms never
	 * configured one; the schema normalizes unsupported codes.
	 */
	private static function wpFormsCurrency(): string {
		$settings = get_option( 'wpforms_settings' );
		$code     = is_array( $settings ) ? ( $settings['currency'] ?? '' ) : '';

		return is_string( $code ) && '' !== trim( $code ) ? trim( $code ) : Money::DEFAULT_CURRENCY;
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

		if ( self::hasPaymentFields( $form ) ) {
			$notes[] = __( 'Payment amounts were imported as-is (currency from the WPForms settings when present). WPForms gateway connections are not migrated: connect Stripe from Relevant Forms → Settings to accept payments.', 'relevant-forms' );
		}

		$notes[] = __( 'Imported as a draft: review and publish when ready. Original WPForms data is untouched.', 'relevant-forms' );

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
