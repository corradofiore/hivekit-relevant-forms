<?php
/**
 * Field list item (left column of the fields editor).
 *
 * @package SentinelForms
 *
 * @var array<string, mixed> $definition Field definition.
 * @var SentinelForms\Forms\Fields\FieldType $field_type Field type instance.
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
<div class="sf-field-item<?php echo $isSelected ? ' sf-field-item--active' : ''; ?>" data-for="<?php echo esc_attr( $fieldId ); ?>" data-type="<?php echo esc_attr( (string) $definition['type'] ); ?>" role="option" aria-selected="<?php echo $isSelected ? 'true' : 'false'; ?>" tabindex="0" draggable="true">
	<div class="sf-field-item__main">
		<span class="sf-field-item__handle" aria-hidden="true"></span>
		<span class="sf-field-item__name"><?php echo esc_html( '' !== $fieldLabel ? $fieldLabel : __( 'Untitled field', 'sentinel-forms' ) ); ?></span>
		<span class="sf-field-item__required"<?php echo $isRequired ? '' : ' hidden'; ?> aria-hidden="true">*</span>
		<span class="sf-field-item__type"><?php echo esc_html( $field_type->label() ); ?></span>
	</div>
	<div class="sf-field-item__actions">
		<button type="button" class="button-link sf-duplicate"><?php esc_html_e( 'Duplicate', 'sentinel-forms' ); ?></button>
		<button type="button" class="button-link sf-remove"><?php esc_html_e( 'Delete', 'sentinel-forms' ); ?></button>
	</div>
</div>
