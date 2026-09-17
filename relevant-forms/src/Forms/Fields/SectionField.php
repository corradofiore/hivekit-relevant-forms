<?php
/**
 * Section separator field.
 *
 * A visible field that renders a separator (optionally with a heading)
 * between groups of fields. It carries no value: nothing is submitted,
 * stored or notified for it.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * section field.
 */
final class SectionField implements FieldType {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'section';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Section separator', 'relevant-forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $definition, mixed $value ): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Sections carry no value at all — the submission handler skips null
	 * sanitized values, so no entry meta or notification row is created.
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		$label   = trim( (string) ( $definition['label'] ?? '' ) );
		$heading = '' !== $label
			? sprintf( '<h3 class="relevant-forms-section-title">%s</h3>', esc_html( $label ) )
			: '';

		return sprintf(
			'<div class="relevant-forms-field relevant-forms-field--section">%s<hr class="relevant-forms-section-separator"></div>',
			$heading
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return [
			'label' => __( 'A New Section', 'relevant-forms' ),
		];
	}
}
