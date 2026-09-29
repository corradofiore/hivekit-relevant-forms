<?php
/**
 * Field visibility rules block (below the Preview panel).
 *
 * Renders the per-field editor for conditional visibility: the default
 * state plus groups of rules (AND inside a group, OR across groups).
 *
 * @package RelevantForms
 *
 * @var array<string, mixed>             $definition                Field definition.
 * @var array<int, array<string, mixed>> $builder_fields            All field definitions (display order).
 * @var int                              $builder_visibility_index  Index of $definition within $builder_fields.
 * @var bool                             $builder_visibility_is_first Whether this block is the first field (always shown).
 * @var RelevantForms\Forms\Form         $form                      Form being edited.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope variables.

$vis_field_id = (string) $definition['id'];
$vis_config   = RelevantForms\Forms\Visibility\VisibilityRules::normalize( $definition['visibility'] ?? null );
$vis_default  = $vis_config['default'] ?? RelevantForms\Forms\Visibility\VisibilityRules::DEFAULT_SHOWN;
$vis_groups   = $vis_config['groups'] ?? [];
$vis_previous = array_slice( $builder_fields, 0, $builder_visibility_index );
$vis_labels   = RelevantForms\Forms\Visibility\VisibilityRules::operatorLabels();

$vis_label_by_id = [];
foreach ( $builder_fields as $vis_field ) {
	if ( is_array( $vis_field ) ) {
		$vis_label_by_id[ (string) ( $vis_field['id'] ?? '' ) ] = trim( (string) ( $vis_field['label'] ?? '' ) );
	}
}
?>
<div class="rf-field-visibility" data-for="<?php echo esc_attr( $vis_field_id ); ?>">
	<p class="rf-field-visibility__intro description" data-first-note<?php echo $builder_visibility_is_first ? '' : ' hidden'; ?>><?php esc_html_e( 'This field is always shown: it has no fields above it for a visibility rule to depend on.', 'relevant-forms' ); ?></p>
	<div class="rf-field-visibility__controls"<?php echo $builder_visibility_is_first ? ' hidden' : ''; ?>>
	<p class="rf-field-visibility__intro"><?php esc_html_e( 'Rules flip the field from its default state when a whole group matches. Rules inside a group are joined with AND, groups with OR.', 'relevant-forms' ); ?></p>

	<p class="rf-field-visibility__default">
		<label for="rf-field-<?php echo esc_attr( $vis_field_id ); ?>-visibility-default"><?php esc_html_e( 'Default visibility', 'relevant-forms' ); ?></label>
		<select id="rf-field-<?php echo esc_attr( $vis_field_id ); ?>-visibility-default" name="relevant_fields[<?php echo esc_attr( $vis_field_id ); ?>][visibility][default]">
			<option value="shown" <?php selected( $vis_default, 'shown' ); ?>><?php esc_html_e( 'Shown', 'relevant-forms' ); ?></option>
			<option value="hidden" <?php selected( $vis_default, 'hidden' ); ?>><?php esc_html_e( 'Hidden', 'relevant-forms' ); ?></option>
		</select>
	</p>

	<div class="rf-field-visibility__groups" data-groups>
		<?php foreach ( $vis_groups as $vis_group_index => $vis_group ) : ?>
			<div class="rf-field-visibility__group" data-group data-index="<?php echo (int) $vis_group_index; ?>">
				<p class="rf-field-visibility__join"<?php echo 0 === (int) $vis_group_index ? ' hidden' : ''; ?>><?php esc_html_e( 'or', 'relevant-forms' ); ?></p>
				<div class="rf-field-visibility__rules" data-rules>
					<?php foreach ( $vis_group as $vis_rule_index => $vis_rule ) : ?>
						<?php
						$vis_source_id      = (string) $vis_rule['field'];
						$vis_source_type    = '';
						$vis_source_choices = [];

						foreach ( $builder_fields as $vis_field ) {
							if ( is_array( $vis_field ) && $vis_source_id === (string) ( $vis_field['id'] ?? '' ) ) {
								$vis_source_type = (string) ( $vis_field['type'] ?? '' );

								$vis_source_field = RelevantForms\Forms\Fields\FieldTypeRegistry::get( $vis_source_type );

								// Choices are stored raw ("Label|value" rows);
								// the field type normalizes them for us.
								if ( $vis_source_field && method_exists( $vis_source_field, 'normalizeChoices' ) ) {
									$vis_source_choices = $vis_source_field->normalizeChoices( $vis_field );
								}
							}
						}

						$vis_is_choice         = in_array( $vis_source_type, [ 'select', 'radio', 'checkbox' ], true );
						$vis_operators         = RelevantForms\Forms\Visibility\VisibilityRules::operatorsFor( $vis_source_type );
						$vis_rule_name_base    = 'relevant_fields[' . $vis_field_id . '][visibility][groups][' . (int) $vis_group_index . '][' . (int) $vis_rule_index . ']';
						$vis_has_source_option = false;
						$vis_has_value_option  = false;
						?>
						<div class="rf-field-visibility__rule" data-rule>
							<select class="rf-visibility-source" name="<?php echo esc_attr( $vis_rule_name_base . '[field]' ); ?>">
								<option value=""><?php esc_html_e( '— Select field —', 'relevant-forms' ); ?></option>
								<?php
								foreach ( $vis_previous as $vis_candidate ) {
									if ( ! is_array( $vis_candidate ) || 'section' === (string) ( $vis_candidate['type'] ?? '' ) ) {
										continue;
									}

									$vis_candidate_id = (string) ( $vis_candidate['id'] ?? '' );

									if ( $vis_candidate_id === $vis_source_id ) {
										$vis_has_source_option = true;
									}

									printf(
										'<option value="%1$s" %2$s>%3$s</option>',
										esc_attr( $vis_candidate_id ),
										selected( $vis_source_id, $vis_candidate_id, false ),
										esc_html( '' !== ( $vis_label_by_id[ $vis_candidate_id ] ?? '' ) ? $vis_label_by_id[ $vis_candidate_id ] : $vis_candidate_id )
									);
								}

								// Rules imported from elsewhere may reference a field
								// that is not above this one (or no longer exists).
								if ( '' !== $vis_source_id && ! $vis_has_source_option ) {
									printf(
										'<option value="%1$s" selected="selected">%2$s</option>',
										esc_attr( $vis_source_id ),
										esc_html( ( '' !== ( $vis_label_by_id[ $vis_source_id ] ?? '' ) ? $vis_label_by_id[ $vis_source_id ] : $vis_source_id ) . ' (' . __( 'not above', 'relevant-forms' ) . ')' )
									);
								}
								?>
							</select>
							<select class="rf-visibility-operator" name="<?php echo esc_attr( $vis_rule_name_base . '[operator]' ); ?>">
								<?php
								if ( ! in_array( $vis_rule['operator'], $vis_operators, true ) ) {
									$vis_operators[] = $vis_rule['operator'];
								}

								foreach ( $vis_operators as $vis_operator ) {
									printf(
										'<option value="%1$s" %2$s>%3$s</option>',
										esc_attr( $vis_operator ),
										selected( $vis_rule['operator'], $vis_operator, false ),
										esc_html( $vis_labels[ $vis_operator ] ?? $vis_operator )
									);
								}
								?>
							</select>
							<span class="rf-field-visibility__value" data-value>
								<?php if ( $vis_is_choice ) : ?>
									<select name="<?php echo esc_attr( $vis_rule_name_base . '[value]' ); ?>">
										<option value=""><?php esc_html_e( '— Select value —', 'relevant-forms' ); ?></option>
										<?php
										foreach ( $vis_source_choices as $vis_choice ) {
											if ( ! is_array( $vis_choice ) ) {
												continue;
											}

											$vis_choice_value = (string) ( $vis_choice['value'] ?? '' );
											$vis_choice_label = (string) ( $vis_choice['label'] ?? $vis_choice_value );

											if ( $vis_choice_value === $vis_rule['value'] ) {
												$vis_has_value_option = true;
											}

											printf(
												'<option value="%1$s" %2$s>%3$s</option>',
												esc_attr( $vis_choice_value ),
												selected( $vis_rule['value'], $vis_choice_value, false ),
												esc_html( $vis_choice_label )
											);
										}

										if ( '' !== $vis_rule['value'] && ! $vis_has_value_option ) {
											printf( '<option value="%1$s" selected="selected">%1$s</option>', esc_attr( $vis_rule['value'] ) );
										}
										?>
									</select>
								<?php else : ?>
									<input type="text" name="<?php echo esc_attr( $vis_rule_name_base . '[value]' ); ?>" value="<?php echo esc_attr( $vis_rule['value'] ); ?>">
								<?php endif; ?>
							</span>
							<button type="button" class="button-link rf-visibility-remove"><?php esc_html_e( 'Remove', 'relevant-forms' ); ?></button>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="rf-field-visibility__group-actions">
					<button type="button" class="button-link rf-visibility-add-rule"><?php esc_html_e( '+ Add rule', 'relevant-forms' ); ?></button>
					<button type="button" class="button-link rf-visibility-remove-group"><?php esc_html_e( 'Remove group', 'relevant-forms' ); ?></button>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<button type="button" class="button-link rf-visibility-add-group"><?php esc_html_e( '+ Add group', 'relevant-forms' ); ?></button>
	</div>
</div>
