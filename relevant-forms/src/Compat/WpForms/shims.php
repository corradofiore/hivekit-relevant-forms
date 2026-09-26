<?php
/**
 * WPForms class surface shims (docs/wpforms-compatibility.md).
 *
 * Loaded only while the compat layer is active and WPForms itself is
 * absent. Addons feature-check `class_exists( 'WPForms_Provider' )` and
 * `class_exists( 'WPForms' )` during bootstrap and extend
 * `WPForms_Provider` to enumerate fields from the original WPForms form
 * records, so minimal stand-ins are required for them to initialize
 * against Relevant Forms (e.g. the Newsletter wpforms addon).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPForms', false ) ) {
	/**
	 * Marker class mirroring WPForms' main class for bootstrap checks.
	 */
	class WPForms {}
}

if ( ! class_exists( 'WPForms_Provider', false ) ) {
	/**
	 * Provider surface: addons subclass this and call `get_form_fields()`
	 * to build field-mapping UIs from original WPForms records. The real
	 * provider also declares `init()` (abstract); consumers always
	 * override it themselves, so it is intentionally omitted here — a
	 * native return type could not be added anyway without breaking
	 * subclasses that mirror WPForms' untyped signature.
	 */
	class WPForms_Provider {

		/**
		 * Field list for an original WPForms form record, parsed from
		 * the record's JSON payload (WPForms shape: keyed by field id,
		 * with `id`, `type`, `label` and `format`).
		 *
		 * @param object|int $form WPForms post object (or post ID).
		 * @return array<int|string, array{id: int|string, type: string, label: string, format: string}>
		 */
		public function get_form_fields( $form ) {
			$post = is_object( $form ) ? $form : get_post( (int) $form );

			if ( ! $post instanceof \WP_Post || ! is_string( $post->post_content ) ) {
				return [];
			}

			$data = json_decode( $post->post_content, true );

			if ( ! is_array( $data ) || ! isset( $data['fields'] ) || ! is_array( $data['fields'] ) ) {
				return [];
			}

			$fields = [];

			foreach ( $data['fields'] as $key => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$id = $field['id'] ?? $key;

				$fields[ $id ] = [
					'id'     => is_numeric( $id ) ? (int) $id : $id,
					'type'   => (string) ( $field['type'] ?? 'text' ),
					'label'  => (string) ( $field['label'] ?? '' ),
					'format' => (string) ( $field['format'] ?? '' ),
				];
			}

			return $fields;
		}
	}
}
