<?php
/**
 * Content block field.
 *
 * A display-only block of author-provided HTML (headings, links, lists,
 * payment instructions and so on) between form fields. It carries no value:
 * nothing is validated, submitted, stored or notified for it.
 *
 * Rendering mirrors WPForms' Content field: the stored HTML is passed
 * through wpautop() for paragraph formatting and then sanitized with a
 * restricted allowlist (headings, paragraphs, links, lists, quotes, code and
 * images) before output. Scripts, styles, frames and inline `style`
 * attributes never survive; shortcodes are intentionally not executed.
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
	 * HTML elements a content block may contain.
	 *
	 * Deliberately narrow: no scripts, styles, frames, forms or inline
	 * `style` attributes, so a content block can never become an arbitrary
	 * code or CSS injection point. Rendering and saving share this list.
	 *
	 * @var array<string, array<string, bool>>
	 */
	public const ALLOWED_HTML = [
		'p'          => [],
		'br'         => [],
		'strong'     => [],
		'b'          => [],
		'em'         => [],
		'i'          => [],
		'a'          => [
			'href'   => true,
			'title'  => true,
			'target' => true,
			'rel'    => true,
		],
		'ul'         => [],
		'ol'         => [],
		'li'         => [],
		'h2'         => [],
		'h3'         => [],
		'h4'         => [],
		'blockquote' => [],
		'code'       => [],
		'pre'        => [],
		'img'        => [
			'src'    => true,
			'alt'    => true,
			'width'  => true,
			'height' => true,
		],
	];

	/**
	 * Sanitize content against the block allowlist.
	 */
	public static function sanitizeContent( string $content ): string {
		return wp_kses( $content, self::ALLOWED_HTML );
	}

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
		return __( 'Content block', 'hivekit-relevant-forms' );
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
			? sprintf( '<div class="relevant-forms-content">%s</div>', self::sanitizeContent( wpautop( $content ) ) )
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
