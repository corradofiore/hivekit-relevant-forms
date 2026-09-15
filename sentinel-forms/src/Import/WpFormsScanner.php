<?php
/**
 * WPForms compatibility scanner (doc §46).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Import;

/**
 * Detects WPForms, enumerates its forms and estimates migration
 * compatibility. Read-only: never modifies or deletes WPForms data.
 */
final class WpFormsScanner {

	/**
	 * Query all WPForms form records (works even when the plugin is
	 * deactivated — the posts remain in the database).
	 *
	 * @return array<int, WpFormsForm>
	 */
	public function forms(): array {
		$posts = get_posts(
			[
				'post_type'   => 'wpforms',
				'numberposts' => -1,
				'post_status' => 'any',
				'orderby'     => 'ID',
				'order'       => 'ASC',
			]
		);

		$forms = [];

		foreach ( $posts as $post ) {
			$form = WpFormsForm::fromJson( (int) $post->ID, (string) $post->post_title, (string) $post->post_content );

			if ( null !== $form ) {
				$forms[] = $form;
			}
		}

		return $forms;
	}

	/**
	 * Whether WPForms (or leftover form records) are present.
	 */
	public function isInstalled(): bool {
		return self::wpFormsActive() || [] !== $this->forms();
	}

	/**
	 * Installed WPForms version, when detectable.
	 */
	public static function version(): string {
		if ( defined( 'WPFORMS_VERSION' ) ) {
			return (string) WPFORMS_VERSION;
		}

		return '';
	}

	/**
	 * Whether the WPForms plugin itself is loaded.
	 */
	public static function wpFormsActive(): bool {
		return defined( 'WPFORMS_VERSION' ) || function_exists( 'wpforms' ) || class_exists( 'WPForms\\WPForms' );
	}

	/**
	 * Scanner report for the admin UI (doc §46 example output).
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		$forms        = $this->forms();
		$compatible   = 0;
		$review       = 0;
		$unsupported  = 0;
		$fieldTypes   = [];
		$uploads      = 0;
		$conditionals = 0;
		$rows         = [];

		foreach ( $forms as $form ) {
			$analysis = $form->analysis();

			if ( WpFormsForm::STATUS_COMPATIBLE === $analysis['status'] ) {
				++$compatible;
			} elseif ( WpFormsForm::STATUS_REVIEW === $analysis['status'] ) {
				++$review;
			} else {
				++$unsupported;
			}

			if ( $analysis['uploads'] ) {
				++$uploads;
			}

			if ( $analysis['conditionals'] ) {
				++$conditionals;
			}

			foreach ( $form->fields as $field ) {
				$type                = (string) ( $field['type'] ?? 'unknown' );
				$fieldTypes[ $type ] = ( $fieldTypes[ $type ] ?? 0 ) + 1;
			}

			$rows[] = [
				'id'      => $form->sourceId,
				'title'   => $form->title,
				'fields'  => count( $form->fields ),
				'mapped'  => count( $form->mappedFields() ),
				'status'  => $analysis['status'],
				'issues'  => $analysis['issues'],
				'uploads' => $analysis['uploads'],
			];
		}

		$total = count( $forms );

		return [
			'installed'     => $this->isInstalled(),
			'active'        => self::wpFormsActive(),
			'version'       => self::version(),
			'total'         => $total,
			'compatible'    => $compatible,
			'review'        => $review,
			'unsupported'   => $unsupported,
			'compatibility' => $total > 0 ? (int) round( $compatible / $total * 100 ) : 0,
			'field_types'   => $fieldTypes,
			'uploads'       => $uploads,
			'conditionals'  => $conditionals,
			'forms'         => $rows,
		];
	}
}
