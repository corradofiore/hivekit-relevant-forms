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
?>
<div class="sf-field-item<?php echo $isSelected ? ' sf-field-item--active' : ''; ?>" data-for="<?php echo esc_attr( $fieldId ); ?>" data-type="<?php echo esc_attr( (string) $definition['type'] ); ?>" role="option" aria-selected="<?php echo $isSelected ? 'true' : 'false'; ?>" tabindex="0" draggable="true">
	<span class="sf-field-item__handle" aria-hidden="true"></span>
	<span class="sf-field-item__name"><?php echo esc_html( '' !== $fieldLabel ? $fieldLabel : __( 'Untitled field', 'sentinel-forms' ) ); ?></span>
	<span class="sf-field-item__type"><?php echo esc_html( $field_type->label() ); ?></span>
	<button type="button" class="button-link sf-remove" title="<?php esc_attr_e( 'Remove field', 'sentinel-forms' ); ?>">&times;</button>
</div>
