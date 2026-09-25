<?php
/**
 * Content block field.
 *
 * A display-only block of author-provided HTML (headings, links, lists,
 * payment instructions and so on) between form fields. It carries no value:
 * nothing is validated, submitted, stored or notified for it.
 *
 * Rendering mirrors WPForms' Content field: the stored HTML is passed
 * through wpautop() for paragraph formatting and then sanitized with
 * wp_kses_post() before output. Shortcodes are intentionally not executed.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * content field.
 */
final class ContentField implements FieldType {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'content';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Content block', 'relevant-forms' );
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
	 * Content blocks carry no value at all — the submission handler skips
	 * null sanitized values, so no entry meta or notification row is
	 * created for them.
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		$heading = trim( (string) ( $definition['label'] ?? '' ) );
		$content = trim( (string) ( $definition['content'] ?? '' ) );

		if ( '' === $heading && '' === $content ) {
			return '';
		}

		$title = '' !== $heading
			? sprintf( '<h3 class="relevant-forms-content-title">%s</h3>', esc_html( $heading ) )
			: '';

		$body = '' !== $content
			? sprintf( '<div class="relevant-forms-content">%s</div>', wp_kses_post( wpautop( $content ) ) )
			: '';

		return sprintf(
			'<div class="relevant-forms-field relevant-forms-field--content">%s%s</div>',
			$title,
			$body
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return [
			'label'   => '',
			'content' => '',
		];
	}
}
