<?php
/**
 * Form usage discovery: which content embeds a form shortcode.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Forms;

/**
 * Finds the posts (pages, posts, templates) whose content embeds a
 * `[sentinel_form id="…"]` shortcode, powering the "Used on" column of
 * the Forms list. Only shortcode usage is discoverable; forms rendered
 * from theme templates via `sentinel_forms_render()` are not detectable.
 */
final class FormUsage {

	/**
	 * Transient key for the cached id → posts map.
	 */
	private const CACHE_KEY = 'sentinel_forms_usage_map';

	/**
	 * Register cache invalidation on post changes.
	 */
	public static function register(): void {
		add_action( 'save_post', [ self::class, 'flush' ] );
		add_action( 'deleted_post', [ self::class, 'flush' ] );
		add_action( 'trashed_post', [ self::class, 'flush' ] );
	}

	/**
	 * Drop the cached usage map.
	 */
	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Usage map: form id → posts rendering that form.
	 *
	 * @param array<int, int> $formIds Optional whitelist of form ids.
	 * @return array<int, array<int, array{id: int, title: string, status: string, status_label: string, edit_url: string, view_url: string}>>
	 */
	public static function map( array $formIds = [] ): array {
		$map = self::cachedMap();

		if ( [] === $formIds ) {
			return $map;
		}

		return array_intersect_key( $map, array_flip( array_map( 'absint', $formIds ) ) );
	}

	/**
	 * Posts rendering one form.
	 *
	 * @return array<int, array{id: int, title: string, status: string, status_label: string, edit_url: string, view_url: string}>
	 */
	public static function forForm( int $formId ): array {
		return self::map()[ $formId ] ?? [];
	}

	/**
	 * Form ids embedded in the given content (attribute order/quoting and
	 * repeated embeds are tolerated; duplicates collapse).
	 *
	 * @return array<int, int>
	 */
	public static function extract( string $content ): array {
		if ( ! has_shortcode( $content, 'sentinel_form' ) ) {
			return [];
		}

		$pattern = get_shortcode_regex( [ 'sentinel_form' ] );

		if ( ! preg_match_all( '/' . $pattern . '/s', $content, $matches ) ) {
			return [];
		}

		$ids = [];

		foreach ( $matches[3] as $attributes ) {
			$atts = shortcode_parse_atts( (string) $attributes );
			$id   = absint( is_array( $atts ) ? ( $atts['id'] ?? 0 ) : 0 );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Cached or freshly-built usage map.
	 *
	 * @return array<int, array<int, array{id: int, title: string, status: string, status_label: string, edit_url: string, view_url: string}>>
	 */
	private static function cachedMap(): array {
		/**
		 * Filters how long the form usage map is cached, in seconds.
		 * Zero disables the cache.
		 *
		 * @param int $ttl Cache TTL in seconds.
		 */
		$ttl = (int) apply_filters( 'sentinel_forms_form_usage_cache_ttl', 120 );

		if ( $ttl <= 0 ) {
			return self::buildMap();
		}

		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map = self::buildMap();

		set_transient( self::CACHE_KEY, $map, $ttl );

		return $map;
	}

	/**
	 * One bounded LIKE query over post content, cached by the caller.
	 *
	 * @return array<int, array<int, array{id: int, title: string, status: string, status_label: string, edit_url: string, view_url: string}>>
	 */
	private static function buildMap(): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( '[sentinel_form' ) . '%';

		// The posts table is scanned once for the marker; per-post parsing below
		// keeps false positives out (shortcode attribute must match a form id).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ID, post_title, post_status, post_type, post_content FROM ' . $wpdb->posts
				. " WHERE post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )"
				. " AND post_type NOT IN ( 'revision', 'attachment', 'nav_menu_item' )"
				. ' AND post_content LIKE %s'
				. ' ORDER BY post_title ASC',
				$like
			)
		);

		$map = [];

		foreach ( (array) $rows as $row ) {
			foreach ( self::extract( (string) $row->post_content ) as $formId ) {
				$map[ $formId ][] = self::describe( $row );
			}
		}

		return $map;
	}

	/**
	 * Display data for one content row.
	 *
	 * @param object $row posts-table row.
	 * @return array{id: int, title: string, status: string, status_label: string, edit_url: string, view_url: string}
	 */
	private static function describe( object $row ): array {
		$id = (int) $row->ID;

		return [
			'id'           => $id,
			'title'        => '' !== (string) $row->post_title
				? (string) $row->post_title
				: __( '(no title)', 'sentinel-forms' ),
			'status'       => (string) $row->post_status,
			'status_label' => self::statusLabel( (string) $row->post_status ),
			'edit_url'     => (string) ( get_edit_post_link( $id, 'raw' ) ?? '' ),
			'view_url'     => (string) ( get_permalink( $id ) ? get_permalink( $id ) : '' ),
		];
	}

	/**
	 * Human label for a post status; empty for published content.
	 */
	private static function statusLabel( string $status ): string {
		$labels = [
			'draft'   => __( 'Draft', 'sentinel-forms' ),
			'pending' => __( 'Pending review', 'sentinel-forms' ),
			'private' => __( 'Private', 'sentinel-forms' ),
			'future'  => __( 'Scheduled', 'sentinel-forms' ),
		];

		if ( isset( $labels[ $status ] ) ) {
			return $labels[ $status ];
		}

		return 'publish' === $status ? '' : ucfirst( str_replace( '_', ' ', $status ) );
	}
}
