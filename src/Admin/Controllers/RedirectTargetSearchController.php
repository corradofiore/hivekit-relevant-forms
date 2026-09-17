<?php
/**
 * AJAX search for redirect picker targets (pages and posts).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Controllers;

use WP_Query;

/**
 * Serves the search selector used by the form editor's redirect picker when
 * a site has too many pages/posts for a plain drop-down.
 */
final class RedirectTargetSearchController {

	/**
	 * AJAX action (also used as the nonce action).
	 */
	public const ACTION = 'relevant_forms_search_redirect_targets';

	/**
	 * Maximum number of matches returned per search.
	 */
	public const LIMIT = 20;

	/**
	 * Minimum search term length.
	 */
	private const MIN_LENGTH = 2;

	/**
	 * Register the AJAX handler.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Handle the AJAX request.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to search pages and posts.', 'relevant-forms' ) ], 403 );
		}

		check_ajax_referer( self::ACTION );

		$term = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['search'] ) ) : '';

		wp_send_json_success( [ 'results' => self::search( $term ) ] );
	}

	/**
	 * Search published pages and posts.
	 *
	 * @param string $term Search term.
	 * @return array<int, array{id: int, title: string, type: string}>
	 */
	public static function search( string $term ): array {
		$term = trim( $term );

		if ( mb_strlen( $term ) < self::MIN_LENGTH ) {
			return [];
		}

		$query = new WP_Query(
			[
				'post_type'              => [ 'page', 'post' ],
				'post_status'            => 'publish',
				's'                      => $term,
				'posts_per_page'         => self::LIMIT,
				'orderby'                => 'relevance',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$results = [];

		foreach ( $query->posts as $post ) {
			$title = get_the_title( $post );

			if ( '' === $title ) {
				$title = __( '(no title)', 'relevant-forms' );
			}

			$results[] = [
				'id'    => (int) $post->ID,
				'title' => $title,
				'type'  => (string) $post->post_type,
			];
		}

		return $results;
	}
}
