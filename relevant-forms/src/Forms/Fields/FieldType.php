<?php
/**
 * Field type contract.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * A form field type: validation, sanitization and rendering.
 */
interface FieldType {

	/**
	 * Machine name of the type.
	 */
	public function type(): string;

	/**
	 * Human readable label of the type.
	 */
	public function label(): string;

	/**
	 * Validate a submitted value against the field definition.
	 *
	 * Returns an error message or null when valid. Required checks are
	 * performed generically by the submission handler.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param mixed                $value      Submitted value.
	 */
	public function validate( array $definition, mixed $value ): ?string;

	/**
	 * Sanitize a submitted value for storage.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param mixed                $value      Submitted value.
	 * @return mixed Safe value (scalar or array of scalars), or null for
	 *               fields that carry no value (e.g. section separators).
	 */
	public function sanitize( array $definition, mixed $value ): mixed;

	/**
	 * Render the field markup including label, help text and error.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param mixed                $value      Current value.
	 * @param string|null          $error      Validation error message.
	 * @param string               $formId     Owning form ID (for ids/names).
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string;

	/**
	 * Default definition values merged when a field is created.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array;
}
