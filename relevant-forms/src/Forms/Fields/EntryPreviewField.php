<?php
/**
 * Entry preview field.
 *
 * A visible layout field that reviews the visitor's own answers before
 * submission: an optional notice followed by the values entered in the
 * fields above it. It carries no value itself — nothing is validated,
 * submitted, stored or notified for it.
 *
 * Empty fields and conditionally hidden fields are omitted; the renderer
 * injects the prepared rows into the definition as `preview_rows` and a
 * small script keeps them in sync while the visitor types.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * entry-preview field.
 */
final class EntryPreviewField implements FieldType {

	/**
	 * Styles accepted by the style setting.
	 *
	 * @var array<int, string>
	 */
	public const STYLES = [ 'basic', 'compact', 'table', 'table_compact' ];

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'entry-preview';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Entry preview', 'relevant-forms' );
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
	 * The preview carries no value at all — the submission handler skips
	 * null sanitized values, so no entry meta or notification row is
	 * created for it.
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		$style = (string) ( $definition['style'] ?? 'basic' );

		if ( ! in_array( $style, self::STYLES, true ) ) {
			$style = 'basic';
		}

		$html = sprintf(
			'<div class="relevant-forms-field relevant-forms-field--entry-preview"><div class="relevant-forms-entry-preview relevant-forms-entry-preview--%s" data-rf-preview data-rf-preview-yes="%s">',
			esc_attr( $style ),
			esc_attr__( 'Yes', 'relevant-forms' )
		);

		$heading = trim( (string) ( $definition['label'] ?? '' ) );

		if ( '' !== $heading ) {
			$html .= sprintf( '<h3 class="relevant-forms-preview-heading">%s</h3>', esc_html( $heading ) );
		}

		$notice = self::notice( $definition );

		if ( '' !== $notice ) {
			$html .= sprintf( '<p class="relevant-forms-preview-notice">%s</p>', esc_html( $notice ) );
		}

		$rows   = (array) ( $definition['preview_rows'] ?? [] );
		$filled = 0;
		$items  = '';

		foreach ( $rows as $row ) {
			$rowId  = (string) ( $row['id'] ?? '' );
			$label  = (string) ( $row['label'] ?? '' );
			$value  = trim( (string) ( $row['value'] ?? '' ) );
			$hidden = '' === $value || ! empty( $row['hidden_by_rule'] );

			if ( ! $hidden ) {
				++$filled;
			}

			$items .= sprintf(
				'<div class="relevant-forms-preview-row" data-rf-preview-row="%s"%s><span class="relevant-forms-preview-label">%s</span><span class="relevant-forms-preview-value">%s</span></div>',
				esc_attr( $rowId ),
				$hidden ? ' hidden' : '',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		$html .= sprintf( '<div class="relevant-forms-preview-rows">%s</div>', $items );
		$html .= sprintf(
			'<p class="relevant-forms-preview-empty" data-rf-preview-empty%s>%s</p>',
			$filled > 0 ? ' hidden' : '',
			esc_html__( 'Filled-in answers will appear here for review.', 'relevant-forms' )
		);
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Notice text for a definition.
	 *
	 * When the notice is enabled but no custom text is saved the default
	 * message is used; a disabled notice renders nothing.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	public static function notice( array $definition ): string {
		if ( empty( $definition['notice_enable'] ) ) {
			return '';
		}

		$notice = trim( (string) ( $definition['notice'] ?? '' ) );

		if ( '' === $notice ) {
			$notice = __( 'Please review your answers before submitting. Nothing has been sent yet — you can go back to make changes.', 'relevant-forms' );
		}

		return $notice;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return [
			'label'         => '',
			'notice_enable' => true,
			'notice'        => '',
			'style'         => 'basic',
		];
	}
}
