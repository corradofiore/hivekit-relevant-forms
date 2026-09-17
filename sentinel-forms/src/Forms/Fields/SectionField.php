<?php
/**
 * Section separator field.
 *
 * A visible field that renders a separator (optionally with a heading)
 * between groups of fields. It carries no value: nothing is submitted,
 * stored or notified for it.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Forms\Fields;

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
		return __( 'Section separator', 'sentinel-forms' );
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
			? sprintf( '<h3 class="sentinel-forms-section-title">%s</h3>', esc_html( $label ) )
			: '';

		return sprintf(
			'<div class="sentinel-forms-field sentinel-forms-field--section">%s<hr class="sentinel-forms-section-separator"></div>',
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
			'label' => __( 'A New Section', 'sentinel-forms' ),
		];
	}
}
