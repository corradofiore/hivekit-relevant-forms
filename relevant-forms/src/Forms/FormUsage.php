<?php
/**
 * Form usage discovery: which content embeds a form shortcode.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms;

/**
 * Finds the posts (pages, posts, templates) whose content embeds a
 * `[relevant_form id="…"]` shortcode or a `relevant-forms/form-selector`
 * block, or whose builder layout (Beaver Builder, Bricks) embeds the form
 * module, powering the "Used on" column of the Forms list. Forms rendered
 * from theme templates via `relevant_forms_render()` are not detectable.
 */
final class FormUsage {

	/**
	 * Transient key for the cached id → posts map.
	 */
	private const CACHE_KEY = 'relevant_forms_usage_map';

	/**
	 * Register cache invalidation on post changes.
	 */
	public static function register(): void {
		add_action( 'save_post', [ self::class, 'flush' ] );
		add_action( 'deleted_post', [ self::class, 'flush' ] );
		add_action( 'trashed_post', [ self::class, 'flush' ] );
		add_action( 'fl_builder_after_save_layout', [ self::class, 'flush' ], 10, 0 );
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
	 * Form ids embedded in the given content: shortcodes (attribute
	 * order/quoting and repeated embeds are tolerated; the Divi module's
	 * `form_id` attribute is honoured) and block editor embeds. Duplicates
	 * collapse.
	 *
	 * @return array<int, int>
	 */
	public static function extract( string $content ): array {
		if ( ! has_shortcode( $content, 'relevant_form' ) && ! str_contains( $content, 'wp:relevant-forms/form-selector' ) ) {
			return [];
		}

		$ids = [];

		if ( has_shortcode( $content, 'relevant_form' ) ) {
			$pattern = get_shortcode_regex( [ 'relevant_form' ] );

			if ( preg_match_all( '/' . $pattern . '/s', $content, $matches ) ) {
				foreach ( $matches[3] as $attributes ) {
					$atts = shortcode_parse_atts( (string) $attributes );
					// `id` is the shortcode attribute; the Divi module stores its
					// field as `form_id` in the shortcode it outputs.
					$id = absint( is_array( $atts ) ? ( $atts['id'] ?? $atts['form_id'] ?? 0 ) : 0 );

					if ( $id > 0 ) {
						$ids[] = $id;
					}
				}
			}
		}

		// Block editor embeds: the id travels in the block comment's JSON
		// attributes ("formId" key) of a relevant-forms/form-selector block.
		if ( preg_match_all( '/<!--\s*wp:relevant-forms\/form-selector(?:\s+(\{[^}]*\}))?\s*\/?-->/s', $content, $blocks ) ) {
			foreach ( $blocks[1] as $json ) {
				if ( '' === $json ) {
					continue;
				}

				$atts = json_decode( $json, true );
				$id   = absint( is_array( $atts ) ? ( $atts['formId'] ?? 0 ) : 0 );

				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Form ids embedded in a builder layout stored in post meta.
	 *
	 * Supported: Beaver Builder (`_fl_builder_data`, `_fl_builder_draft` — a
	 * flat node list where modules carry `settings->type` and `settings->form_id`)
	 * and Bricks (`_bricks_page_content[_2]` — a JSON element tree with
	 * `name`/`settings.formId` and nested `children`).
	 *
	 * @param string $metaKey   Meta key.
	 * @param string $metaValue Raw (usually serialized or JSON) meta value.
	 * @return array<int, int>
	 */
	public static function extractFromBuilderMeta( string $metaKey, string $metaValue ): array {
		if ( in_array( $metaKey, [ '_fl_builder_data', '_fl_builder_draft' ], true ) ) {
			return self::extractFromBeaverLayout( $metaValue );
		}

		if ( in_array( $metaKey, [ '_bricks_page_content', '_bricks_page_content_2' ], true ) ) {
			return self::extractFromBricksLayout( $metaValue );
		}

		return [];
	}

	/**
	 * Beaver Builder layout data: flat node objects; module nodes carry the
	 * module type in `settings->type`.
	 *
	 * @param string $metaValue Serialized layout data.
	 * @return array<int, int>
	 */
	private static function extractFromBeaverLayout( string $metaValue ): array {
		$data = maybe_unserialize( $metaValue );

		// Defensively unwrap a second level: WordPress double-serializes
		// values that were already serialized when they were saved.
		if ( is_string( $data ) ) {
			$data = maybe_unserialize( $data );
		}

		if ( ! is_array( $data ) ) {
			return [];
		}

		$ids = [];

		foreach ( $data as $node ) {
			if ( ! is_object( $node ) || ! isset( $node->settings ) || ! is_object( $node->settings ) ) {
				continue;
			}

			if ( 'relevant-form' !== ( $node->settings->type ?? '' ) ) {
				continue;
			}

			$id = absint( $node->settings->form_id ?? 0 );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Bricks element tree (JSON; legacy payloads may be serialized).
	 *
	 * @param string $metaValue JSON (or serialized) element tree.
	 * @return array<int, int>
	 */
	private static function extractFromBricksLayout( string $metaValue ): array {
		$data = json_decode( $metaValue, true );

		if ( ! is_array( $data ) ) {
			$data = maybe_unserialize( $metaValue );
		}

		if ( ! is_array( $data ) ) {
			return [];
		}

		$ids = [];

		self::collectBricksIds( $data, $ids );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Walk a Bricks element tree and collect the form ids of relevant-form
	 * elements.
	 *
	 * @param array<int|string, mixed> $elements Element list.
	 * @param array<int, int>          $ids      Collected form ids (by reference).
	 */
	private static function collectBricksIds( array $elements, array &$ids ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( 'relevant-form' === ( $element['name'] ?? '' ) ) {
				$id = absint( $element['settings']['formId'] ?? 0 );

				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}

			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				self::collectBricksIds( $element['children'], $ids );
			}
		}
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
		$ttl = (int) apply_filters( 'relevant_forms_form_usage_cache_ttl', 120 );

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

		$like       = '%' . $wpdb->esc_like( '[relevant_form' ) . '%';
		$like_block = '%' . $wpdb->esc_like( 'wp:relevant-forms/form-selector' ) . '%';

		// The posts table is scanned once for the markers; per-post parsing below
		// keeps false positives out (shortcode attribute / block attribute must
		// match a form id).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ID, post_title, post_status, post_type, post_content FROM ' . $wpdb->posts
				. " WHERE post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )"
				. " AND post_type NOT IN ( 'revision', 'attachment', 'nav_menu_item' )"
				. ' AND ( post_content LIKE %s OR post_content LIKE %s )'
				. ' ORDER BY post_title ASC',
				$like,
				$like_block
			)
		);

		$map = [];

		foreach ( (array) $rows as $row ) {
			foreach ( self::extract( (string) $row->post_content ) as $formId ) {
				$map[ $formId ][] = self::describe( $row );
			}
		}

		// Builder layouts live in post meta (Beaver Builder, Bricks), not in
		// the post content. The module slug is the LIKE marker for both.
		$like_builder = '%' . $wpdb->esc_like( 'relevant-form' ) . '%';

		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.ID, p.post_title, p.post_status, p.post_type, pm.meta_key, pm.meta_value FROM ' . $wpdb->postmeta . ' pm'
				. ' INNER JOIN ' . $wpdb->posts . ' p ON p.ID = pm.post_id'
				. " WHERE pm.meta_key IN ( '_fl_builder_data', '_fl_builder_draft', '_bricks_page_content', '_bricks_page_content_2' )"
				. " AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )"
				. " AND p.post_type NOT IN ( 'revision', 'attachment', 'nav_menu_item' )"
				. ' AND pm.meta_value LIKE %s'
				. ' ORDER BY p.post_title ASC',
				$like_builder
			)
		);

		foreach ( (array) $meta_rows as $row ) {
			foreach ( self::extractFromBuilderMeta( (string) $row->meta_key, (string) $row->meta_value ) as $formId ) {
				$map[ $formId ][] = self::describe( $row );
			}
		}

		// A post may match several scans (content and layout, or published +
		// draft layout data): keep one entry per post and form, ordered by
		// title like the scans themselves.
		foreach ( $map as $formId => $entries ) {
			$unique = [];

			foreach ( $entries as $entry ) {
				$unique[ $entry['id'] ] = $entry;
			}

			$unique = array_values( $unique );

			usort(
				$unique,
				static fn ( array $a, array $b ): int => strcasecmp( $a['title'], $b['title'] )
			);

			$map[ $formId ] = $unique;
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
				: __( '(no title)', 'relevant-forms' ),
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
			'draft'   => __( 'Draft', 'relevant-forms' ),
			'pending' => __( 'Pending review', 'relevant-forms' ),
			'private' => __( 'Private', 'relevant-forms' ),
			'future'  => __( 'Scheduled', 'relevant-forms' ),
		];

		if ( isset( $labels[ $status ] ) ) {
			return $labels[ $status ];
		}

		return 'publish' === $status ? '' : ucfirst( str_replace( '_', ' ', $status ) );
	}
}
