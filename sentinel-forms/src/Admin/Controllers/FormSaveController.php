<?php
/**
 * Form save controller (admin-post action).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Admin\Controllers;

use SentinelForms\Forms\Fields\FieldTypeRegistry;
use SentinelForms\Forms\FormRepository;
use SentinelForms\Forms\FormSchema;
use SentinelForms\Support\Logger;

/**
 * Persists the form builder payload.
 */
final class FormSaveController {

	/**
	 * Register the admin-post action.
	 */
	public static function register(): void {
		add_action( 'admin_post_sentinel_forms_save_form', [ self::class, 'handle' ] );
	}

	/**
	 * Handle the save request.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'sentinel-forms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'sentinel_form_save' );

		$raw = wp_unslash( $_POST );

		$formId = isset( $raw['form_id'] ) ? absint( $raw['form_id'] ) : 0;
		$name   = sanitize_text_field( (string) ( $raw['form_name'] ?? '' ) );
		$status = 'published' === (string) ( $raw['form_status'] ?? '' ) ? 'published' : 'draft';

		$fields = self::parseFields( $raw['sentinel_fields'] ?? [] );

		$emails   = self::parseLines( (string) ( $raw['notification_emails'] ?? '' ) );
		$message  = sanitize_textarea_field( (string) ( $raw['confirmation_message'] ?? '' ) );
		$redirect = esc_url_raw( (string) ( $raw['redirect_url'] ?? '' ) );

		$schema = FormSchema::empty()
			->withName( $name )
			->withFields( $fields )
			->withNotifications( $emails )
			->withConfirmation( $message, $redirect );

		$errors = [];
		if ( '' === trim( $name ) ) {
			$errors[] = __( 'The form name is required.', 'sentinel-forms' );
		}
		$errors = array_merge( $errors, $schema->validate() );

		if ( $errors !== [] ) {
			wp_die(
				'<h1>' . esc_html__( 'The form could not be saved.', 'sentinel-forms' ) . '</h1>'
				. '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $errors ) ) . '</li></ul>'
				. '<p><a href="' . esc_url( admin_url( 'admin.php?page=sentinel-form-new' . ( $formId > 0 ? '&form_id=' . $formId : '' ) ) ) . '">&larr; ' . esc_html__( 'Go back', 'sentinel-forms' ) . '</a></p>',
				esc_html__( 'Form invalid', 'sentinel-forms' ),
				[ 'response' => 400 ]
			);
		}

		$repository = new FormRepository();

		if ( $formId > 0 ) {
			$repository->update( $formId, $name, $schema, $status );
		} else {
			$formId = $repository->create( $name, $schema, $status );
		}

		Logger::info(
			'Form saved',
			[
				'form_id' => $formId,
				'status'  => $status,
			]
		);

		wp_safe_redirect( admin_url( 'admin.php?page=sentinel-form-new&form_id=' . $formId . '&saved=1' ) );
		exit;
	}

	/**
	 * Parse posted field rows into ordered definitions.
	 *
	 * @param mixed $posted The sentinel_fields POST value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function parseFields( mixed $posted ): array {
		if ( ! is_array( $posted ) ) {
			return [];
		}

		$fields = [];

		foreach ( $posted as $rawField ) {
			if ( ! is_array( $rawField ) ) {
				continue;
			}

			$id   = sanitize_key( (string) ( $rawField['id'] ?? '' ) );
			$type = sanitize_key( (string) ( $rawField['type'] ?? '' ) );

			if ( '' === $id || ! FieldTypeRegistry::has( $type ) ) {
				continue;
			}

			$definition = [
				'id'            => $id,
				'type'          => $type,
				'label'         => sanitize_text_field( (string) ( $rawField['label'] ?? '' ) ),
				'required'      => ! empty( $rawField['required'] ),
				'placeholder'   => sanitize_text_field( (string) ( $rawField['placeholder'] ?? '' ) ),
				'help_text'     => sanitize_text_field( (string) ( $rawField['help_text'] ?? '' ) ),
				'default_value' => sanitize_text_field( (string) ( $rawField['default_value'] ?? '' ) ),
			];

			if ( in_array( $type, [ 'select', 'radio', 'checkbox' ], true ) ) {
				$definition['choices'] = self::parseLines( (string) ( $rawField['choices'] ?? '' ) );
			}

			if ( 'file' === $type ) {
				$definition['upload_policy'] = [
					'allowed_ext' => self::parseExtensions( (string) ( $rawField['upload_policy']['allowed_ext'] ?? '' ) ),
					'max_size'    => absint( $rawField['upload_policy']['max_size'] ?? 0 ) * 1048576,
					'max_files'   => absint( $rawField['upload_policy']['max_files'] ?? 1 ),
					'scan'        => empty( $rawField['upload_policy']['scan'] ) ? false : true,
					'scan_level'  => 'deep' === (string) ( $rawField['upload_policy']['scan_level'] ?? '' ) ? 'deep' : 'basic',
				];
			}

			$fields[] = $definition;
		}

		return $fields;
	}

	/**
	 * Split a textarea into trimmed, de-duplicated lines.
	 *
	 * @return array<int, string>
	 */
	private static function parseLines( string $text ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $text );

		if ( false === $lines ) {
			return [];
		}

		$result = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$result[] = $line;
			}
		}

		return array_values( array_unique( $result ) );
	}

	/**
	 * Split an extension list into sanitized, lowercased tokens.
	 *
	 * @return array<int, string>
	 */
	private static function parseExtensions( string $text ): array {
		$extensions = [];

		foreach ( self::parseLines( $text ) as $line ) {
			$token = strtolower( ltrim( trim( $line ), '.' ) );
			$token = preg_replace( '/[^a-z0-9]/', '', $token );

			if ( is_string( $token ) && '' !== $token ) {
				$extensions[] = $token;
			}
		}

		return array_values( array_unique( $extensions ) );
	}
}
