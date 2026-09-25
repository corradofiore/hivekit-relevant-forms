<?php
/**
 * Contract for field types carrying choices.
 *
 * Implemented by select, radio, checkbox and the priced payment choice
 * fields (via ChoiceTrait). Callers that need "submitted value → label"
 * resolution (the entry preview) can rely on this instead of duck typing.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * Field type with normalized choices.
 */
interface ChoiceFieldType {

	/**
	 * Normalize a choices definition.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @return array<int, array{label: string, value: string}>
	 */
	public function normalizeChoices( array $definition ): array;
}
