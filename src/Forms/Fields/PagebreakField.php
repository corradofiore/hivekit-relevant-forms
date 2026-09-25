<?php
/**
 * Page break field: starts a new page in multi-page forms.
 *
 * Layout element only — it carries no value and is never stored or
 * notified. The renderer groups the form into pages at every page break;
 * front-end navigation is progressive enhancement: without JavaScript all
 * pages render stacked and the form submits as a single request.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * page break field.
 */
final class PagebreakField implements FieldType {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'pagebreak';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Page break', 'relevant-forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $definition, mixed $value ): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize( array $definition, mixed $value ): mixed {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The visible marker between pages (also the editor preview sample).
	 * The page title is rendered as the following page's heading by the
	 * form renderer.
	 */
	public function render( array $definition, mixed $value, ?string $error, string $formId ): string {
		return '<hr class="relevant-forms-pagebreak" role="separator">';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return [
			'label' => '',
		];
	}
}
