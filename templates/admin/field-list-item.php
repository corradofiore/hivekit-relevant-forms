<?php
/**
 * Field list item (left column of the fields editor).
 *
 * @package RelevantForms
 *
 * @var array<string, mixed> $definition Field definition.
 * @var RelevantForms\Forms\Fields\FieldType $field_type Field type instance.
 * @var bool $is_first Whether this is the initially selected item.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.

$fieldId    = (string) $definition['id'];
$fieldLabel = trim( (string) ( $definition['label'] ?? '' ) );
$isSelected = ! empty( $is_first );
$isRequired = ! empty( $definition['required'] );
?>
<div class="rf-field-item<?php echo $isSelected ? ' rf-field-item--active' : ''; ?>" data-for="<?php echo esc_attr( $fieldId ); ?>" data-type="<?php echo esc_attr( (string) $definition['type'] ); ?>" role="option" aria-selected="<?php echo $isSelected ? 'true' : 'false'; ?>" tabindex="0" draggable="true">
	<span class="rf-field-item__handle" aria-hidden="true"></span>
	<div class="rf-field-item__content">
		<div class="rf-field-item__main">
			<span class="rf-field-item__name"><?php echo esc_html( '' !== $fieldLabel ? $fieldLabel : __( 'Untitled field', 'relevant-forms' ) ); ?></span>
			<span class="rf-field-item__required"<?php echo $isRequired ? '' : ' hidden'; ?> aria-hidden="true">*</span>
			<span class="rf-field-item__type"><?php echo esc_html( $field_type->label() ); ?></span>
		</div>
		<div class="rf-field-item__actions">
			<button type="button" class="button-link rf-edit"><?php esc_html_e( 'Edit', 'relevant-forms' ); ?></button>
			<button type="button" class="button-link rf-duplicate"><?php esc_html_e( 'Duplicate', 'relevant-forms' ); ?></button>
			<button type="button" class="button-link rf-remove"><?php esc_html_e( 'Delete', 'relevant-forms' ); ?></button>
		</div>
	</div>
</div>
