<?php
/**
 * Single field row in the form builder.
 *
 * @package SentinelForms
 *
 * @var array<string, mixed> $definition Field definition.
 * @var SentinelForms\Forms\Fields\FieldType $field_type Field type instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fieldId  = (string) $definition['id'];
$isChoice = in_array( (string) $definition['type'], [ 'select', 'radio', 'checkbox' ], true );
$isHidden = 'hidden' === (string) $definition['type'];
?>
<div class="sf-field" data-type="<?php echo esc_attr( (string) $definition['type'] ); ?>">
	<div class="sf-field-head">
		<strong class="sf-field-title"><?php echo esc_html( $field_type->label() ); ?></strong>
		<span class="sf-field-actions">
			<button type="button" class="button button-small sf-move-up" title="<?php esc_attr_e( 'Move up', 'sentinel-forms' ); ?>">&uarr;</button>
			<button type="button" class="button button-small sf-move-down" title="<?php esc_attr_e( 'Move down', 'sentinel-forms' ); ?>">&darr;</button>
			<button type="button" class="button button-small sf-remove" title="<?php esc_attr_e( 'Remove field', 'sentinel-forms' ); ?>">&times;</button>
		</span>
	</div>

	<div class="sf-field-body">
		<input type="hidden" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][id]" value="<?php echo esc_attr( $fieldId ); ?>">
		<input type="hidden" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][type]" value="<?php echo esc_attr( (string) $definition['type'] ); ?>">

		<?php if ( ! $isHidden ) : ?>
			<p>
				<label><?php esc_html_e( 'Label', 'sentinel-forms' ); ?><br>
					<input type="text" class="widefat sf-input-label" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][label]" value="<?php echo esc_attr( (string) ( $definition['label'] ?? '' ) ); ?>">
				</label>
			</p>
			<p>
				<label><input type="checkbox" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][required]" value="1" <?php checked( ! empty( $definition['required'] ) ); ?>> <?php esc_html_e( 'Required', 'sentinel-forms' ); ?></label>
			</p>
		<?php endif; ?>

		<?php if ( ! $isHidden && ! in_array( (string) $definition['type'], [ 'consent' ], true ) ) : ?>
			<p>
				<label><?php esc_html_e( 'Placeholder', 'sentinel-forms' ); ?><br>
					<input type="text" class="widefat" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][placeholder]" value="<?php echo esc_attr( (string) ( $definition['placeholder'] ?? '' ) ); ?>">
				</label>
			</p>
			<p>
				<label><?php esc_html_e( 'Help text', 'sentinel-forms' ); ?><br>
					<input type="text" class="widefat" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][help_text]" value="<?php echo esc_attr( (string) ( $definition['help_text'] ?? '' ) ); ?>">
				</label>
			</p>
		<?php endif; ?>

		<?php if ( $isChoice ) : ?>
			<p>
				<label><?php esc_html_e( 'Choices', 'sentinel-forms' ); ?><br>
					<textarea class="widefat" rows="4" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][choices]" placeholder="<?php esc_attr_e( 'One per line. Optional value separated by |\ne.g. Red|red', 'sentinel-forms' ); ?>"><?php echo esc_textarea( implode( "\n", array_map( static fn ( array $choice ): string => $choice['label'] === $choice['value'] ? $choice['label'] : $choice['label'] . '|' . $choice['value'], $field_type->normalizeChoices( $definition ) ) ) ); ?></textarea>
				</label>
			</p>
		<?php endif; ?>

		<p>
			<label><?php esc_html_e( 'Default value', 'sentinel-forms' ); ?><br>
				<input type="text" class="widefat" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][default_value]" value="<?php echo esc_attr( (string) ( $definition['default_value'] ?? '' ) ); ?>">
			</label>
		</p>
	</div>
</div>
