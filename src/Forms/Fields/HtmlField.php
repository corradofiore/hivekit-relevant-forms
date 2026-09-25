<?php
/**
 * Custom HTML field.
 *
 * A display-only block of raw author code (embeds, styles, tracking
 * snippets...). Its whole purpose is to be output as authored: the markup
 * is NOT escaped on the front end and shortcodes are NOT executed.
 *
 * Threat model and gates (option A, agreed 2026-09-26):
 * - Form definitions are editable only by users with `manage_options`.
 * - The raw code is only STORABLE by users who additionally have
 *   `unfiltered_html` (single-site administrators; on multisite only super
 *   admins). Without the capability the editor warns and both the save
 *   controller and the importer sanitize the code with wp_kses_post(),
 *   mirroring how WordPress core treats post content for such users.
 * - The admin preview panel never executes the code: it shows a
 *   placeholder instead (rendering happens only on the front end).
 * - Migrated code is imported as-is with a report note asking the
 *   administrator to review it.
 *
 * It carries no value: nothing is validated, submitted, stored or
 * notified for it.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * html field.
 */
final class HtmlField implements FieldType {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'html';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Custom HTML', 'relevant-forms' );
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
	 * Custom HTML blocks carry no value at all — the submission handler
	 * skips null sanitized values, so no entry meta or notification row is
	 * created for them.
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		$code = trim( (string) ( $definition['code'] ?? '' ) );

		if ( '' === $code ) {
			return '';
		}

		return sprintf(
			'<div class="relevant-forms-field relevant-forms-field--html">%s</div>',
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw code is the point of this field; it can only be stored by users with unfiltered_html (see the class docblock).
			force_balance_tags( $code )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return [
			'label' => '',
			'code'  => '',
		];
	}
}
