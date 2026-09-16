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
	 * Above this many published pages and posts the redirect picker becomes a
	 * search selector instead of a plain drop-down.
	 */
	private const REDIRECT_SEARCH_THRESHOLD = 30;

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
		$formId = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;

		$form      = $formId > 0 ? ( new FormRepository() )->find( $formId ) : null;
		$types     = FieldTypeRegistry::all();
		$isEditing = null !== $form;

		if ( null === $form ) {
			$form = new Form( 0, '', 'draft', \SentinelForms\Forms\FormSchema::empty(), '', '' );
		}

		$redirectSearch   = self::useRedirectSearch();
		$redirectTargets  = $redirectSearch ? [] : self::redirectTargets();
		$redirectSelected = self::selectedRedirectTarget( $form->schema->redirectPageId(), $redirectSearch );

		require SENTINEL_FORMS_PATH . 'templates/admin/form-edit.php';
	}

	/**
	 * Whether the site has enough published pages/posts to warrant a search
	 * selector instead of a plain drop-down.
	 */
	private static function useRedirectSearch(): bool {
		$pages = (array) wp_count_posts( 'page' );
		$posts = (array) wp_count_posts( 'post' );

		$total = (int) ( $pages['publish'] ?? 0 ) + (int) ( $posts['publish'] ?? 0 );

		return $total > self::REDIRECT_SEARCH_THRESHOLD;
	}

	/**
	 * The current redirect anchor as a displayable item (search mode).
	 *
	 * @param int  $anchorId Current redirect anchor ID.
	 * @param bool $search   Whether the search selector is used.
	 * @return array{id: int, title: string}|null
	 */
	private static function selectedRedirectTarget( int $anchorId, bool $search ): ?array {
		if ( ! $search || $anchorId <= 0 ) {
			return null;
		}

		$title = get_the_title( $anchorId );

		return [
			'id'    => $anchorId,
			'title' => '' === $title ? __( '(no title)', 'sentinel-forms' ) : $title,
		];
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
