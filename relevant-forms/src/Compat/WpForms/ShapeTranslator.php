<?php
/**
 * WPForms shape translation (docs/wpforms-compatibility.md).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Compat\WpForms;

use RelevantForms\Forms\Form;

/**
 * Translates Relevant Forms data into WPForms-compatible shapes:
 * `$fields`, `$form_data` and entry payloads. Pure and unit-testable.
 */
final class ShapeTranslator {

	/**
	 * Relevant type → WPForms type.
	 *
	 * @var array<string, string>
	 */
	public const WP_TYPE_BY_RELEVANT = [
		'text'     => 'text',
		'textarea' => 'textarea',
		'email'    => 'email',
		'tel'      => 'phone',
		'select'   => 'select',
		'radio'    => 'radio',
		'checkbox' => 'checkbox',
		'hidden'   => 'hidden',
		'number'   => 'number',
		'date'     => 'date',
		'url'      => 'url',
		'file'     => 'file-upload',
		'consent'  => 'checkbox',
	];

	/**
	 * Build the WPForms `$fields` parameter.
	 *
	 * @param Form                 $form   Form entity.
	 * @param array<string, mixed> $values Sanitized submission values.
	 * @return array<string, array{id: int|string, name: string, value: string, type: string}>
	 */
	public static function fields( Form $form, array $values ): array {
		$fields = [];

		foreach ( $form->schema->fields() as $definition ) {
			$id = (string) ( $definition['id'] ?? '' );

			if ( '' === $id ) {
				continue;
			}

			$value = $values[ $id ] ?? '';

			if ( is_array( $value ) ) {
				$value = implode( "\n", array_map( 'strval', $value ) );
			}

			$fields[ $id ] = [
				'id'    => is_numeric( $id ) ? (int) $id : $id,
				'name'  => (string) ( $definition['label'] ?? '' ),
				'value' => (string) $value,
				'type'  => self::WP_TYPE_BY_RELEVANT[ (string) ( $definition['type'] ?? '' ) ] ?? 'text',
			];
		}

		return $fields;
	}

	/**
	 * Build the WPForms `$form_data` parameter.
	 *
	 * @return array<string, mixed>
	 */
	public static function formData( Form $form ): array {
		$fields = [];

		foreach ( $form->schema->fields() as $definition ) {
			$id = (string) ( $definition['id'] ?? '' );

			if ( '' === $id ) {
				continue;
			}

			$fields[ $id ] = [
				'id'       => is_numeric( $id ) ? (int) $id : $id,
				'type'     => self::WP_TYPE_BY_RELEVANT[ (string) ( $definition['type'] ?? '' ) ] ?? 'text',
				'label'    => (string) ( $definition['label'] ?? '' ),
				'required' => ! empty( $definition['required'] ) ? '1' : '',
			];
		}

		return [
			'id'       => $form->id,
			'name'     => $form->name,
			'fields'   => $fields,
			'settings' => [
				'form_title'  => $form->name,
				'submit_text' => __( 'Submit', 'relevant-forms' ),
			],
		];
	}
}
