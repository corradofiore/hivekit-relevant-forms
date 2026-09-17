<?php
/**
 * Field preview block (Preview panel below the fields editor).
 *
 * The markup is produced by the same renderer as the front end and kept in
 * sync by admin.js while the field is edited on the right.
 *
 * @package SentinelForms
 *
 * @var array<string, mixed> $definition Field definition.
 * @var SentinelForms\Forms\Fields\FieldType $field_type Field type instance.
 * @var SentinelForms\Forms\Form $form Form being edited (for sample ids).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.

$fieldId  = (string) $definition['id'];
$isHidden = 'hidden' === (string) $definition['type'];
$sample   = $isHidden
	? '<p class="description">' . esc_html__( 'Hidden fields are not visible on the front end.', 'sentinel-forms' ) . '</p>'
	: $field_type->render( $definition, null, null, (string) $form->id );

// The preview must never take part in the save form: pointing its controls
// at a non-existent form id leaves them without a form owner, so browser
// validation (required etc.) and submission skip them entirely.
$sample = (string) preg_replace(
	'/<(input|select|textarea)\b/',
	'<$1 form="sentinel-forms-preview"',
	$sample
);
?>
<div class="sf-field-preview" data-for="<?php echo esc_attr( $fieldId ); ?>" inert>
	<div class="sentinel-forms">
		<?php echo $sample; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field markup escaped by the field renderers. ?>
	</div>
</div>
