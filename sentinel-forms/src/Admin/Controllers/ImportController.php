<?php
/**
 * WPForms import controller (admin-post action).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Controllers;

use SentinelForms\Import\WpFormsForm;
use SentinelForms\Import\WpFormsImporter;
use SentinelForms\Import\WpFormsScanner;

/**
 * Runs the WPForms importer for one form or all compatible forms.
 * Read-only on the WPForms side; imported forms are created as drafts.
 */
final class ImportController {

	/**
	 * Register the admin-post action.
	 */
	public static function register(): void {
		add_action( 'admin_post_sentinel_forms_import_wpforms', [ self::class, 'handle' ] );
	}

	/**
	 * Handle the import request.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'sentinel_import_wpforms' );

		$raw = wp_unslash( $_POST );

		$scanner  = new WpFormsScanner();
		$importer = new WpFormsImporter();
		$rows     = [];

		if ( ! empty( $raw['import_all'] ) ) {
			foreach ( $scanner->forms() as $form ) {
				// Bulk import skips forms flagged unsupported (integrations).
				if ( WpFormsForm::STATUS_UNSUPPORTED === $form->analysis()['status'] ) {
					continue;
				}

				$rows[] = $importer->import( $form );
			}
		} else {
			$sourceId = isset( $raw['wpforms_form_id'] ) ? absint( $raw['wpforms_form_id'] ) : 0;

			foreach ( $scanner->forms() as $form ) {
				if ( $form->sourceId === $sourceId ) {
					$rows[] = $importer->import( $form );
					break;
				}
			}
		}

		if ( [] === $rows ) {
			$rows[] = [
				'source_id' => 0,
				'title'     => '',
				'status'    => 'failed',
				'form_id'   => 0,
				'skipped'   => [],
				'notes'     => [],
				'error'     => __( 'No importable WPForms form was found.', 'sentinel-forms' ),
			];
		}

		WpFormsImporter::rememberReport(
			[
				'at'   => current_time( 'mysql', true ),
				'rows' => $rows,
			]
		);

		$url = (string) apply_filters(
			'sentinel_forms_redirect',
			admin_url( 'admin.php?page=sentinel-import&imported=1' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
