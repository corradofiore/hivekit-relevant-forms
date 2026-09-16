<?php
/**
 * Form editor admin page (create and edit).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Pages;

use SentinelForms\Forms\Fields\FieldTypeRegistry;
use SentinelForms\Forms\Form;
use SentinelForms\Forms\FormRepository;

/**
 * Renders the form builder: fields, confirmation and notification settings.
 */
final class FormEditPage {

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
		$formId = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;

		$form            = $formId > 0 ? ( new FormRepository() )->find( $formId ) : null;
		$types           = FieldTypeRegistry::all();
		$redirectTargets = self::redirectTargets();
		$isEditing       = null !== $form;

		if ( null === $form ) {
			$form = new Form( 0, '', 'draft', \SentinelForms\Forms\FormSchema::empty(), '', '' );
		}

		require SENTINEL_FORMS_PATH . 'templates/admin/form-edit.php';
	}

	/**
	 * Published pages and posts for the redirect picker, grouped by type.
	 *
	 * @return array<int, array{label: string, items: array<int, array{id: int, title: string}>}>
	 */
	private static function redirectTargets(): array {
		$groups = [];

		foreach (
			[
				'page' => __( 'Pages', 'sentinel-forms' ),
				'post' => __( 'Posts', 'sentinel-forms' ),
			] as $postType => $label
		) {
			$posts = get_posts(
				[
					'post_type'      => $postType,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				]
			);

			$items = [];

			foreach ( $posts as $post ) {
				$title = get_the_title( $post );

				if ( '' === $title ) {
					$title = __( '(no title)', 'sentinel-forms' );
				}

				$items[] = [
					'id'    => (int) $post->ID,
					'title' => $title,
				];
			}

			if ( [] !== $items ) {
				$groups[] = [
					'label' => $label,
					'items' => $items,
				];
			}
		}

		return $groups;
	}
}
