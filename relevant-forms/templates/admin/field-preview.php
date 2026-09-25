<?php
/**
 * Field preview block (Preview panel below the fields editor).
 *
 * The markup is produced by the same renderer as the front end and kept in
 * sync by admin.js while the field is edited on the right.
 *
 * @package RelevantForms
 *
 * @var array<string, mixed> $definition Field definition.
 * @var RelevantForms\Forms\Fields\FieldType $field_type Field type instance.
 * @var RelevantForms\Forms\Form $form Form being edited (for sample ids).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.

$fieldId  = (string) $definition['id'];
$isHidden = 'hidden' === (string) $definition['type'];
$isHtml   = 'html' === (string) $definition['type'];

if ( $isHidden ) {
	$sample = '<p class="description">' . esc_html__( 'Hidden fields are not visible on the front end.', 'relevant-forms' ) . '</p>';
} elseif ( $isHtml ) {
	// Never execute raw author code inside wp-admin: render a placeholder.
	$sample = '<p class="description">' . esc_html__( 'Custom HTML block: rendered as authored on the live form, so it is not previewed here.', 'relevant-forms' ) . '</p>';
} else {
	$sample = $field_type->render( $definition, null, null, (string) $form->id );
}
$summary = RelevantForms\Forms\Visibility\VisibilityRules::summary( $definition, $form->schema->fields() );

// The preview must never take part in the save form: pointing its controls
// at a non-existent form id leaves them without a form owner, so browser
// validation (required etc.) and submission skip them entirely.
$sample = (string) preg_replace(
	'/<(input|select|textarea)\b/',
	'<$1 form="relevant-forms-preview"',
	$sample
);
?>
<div class="rf-field-preview" data-for="<?php echo esc_attr( $fieldId ); ?>" inert>
	<div class="relevant-forms">
		<?php echo $sample; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field markup escaped by the field renderers. ?>
	</div>
	<?php if ( '' !== $summary ) : ?>
		<p class="rf-field-preview__visibility"><?php echo esc_html( $summary ); ?></p>
	<?php endif; ?>
</div>
