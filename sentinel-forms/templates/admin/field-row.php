<?php
/**
 * Single field editor panel (right column of the fields editor).
 *
 * The panel prepends the field type as plain text and ends with a sample
 * rendering of the field, produced by the same renderer as the front end.
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

$fieldId   = (string) $definition['id'];
$isChoice  = in_array( (string) $definition['type'], [ 'select', 'radio', 'checkbox' ], true );
$isHidden  = 'hidden' === (string) $definition['type'];
$isSection = 'section' === (string) $definition['type'];

// Base DOM id of the preview controls, used by admin.js to update the
// preview (label, required, placeholder, help text...) while typing.
$controlId = 'sentinel-field-' . (string) $form->id . '-' . $fieldId;

// Id prefix of the editor inputs (headers + fields two-column layout).
$inputIdBase = 'sf-field-' . $fieldId;
?>
<div class="sf-field-editor" data-for="<?php echo esc_attr( $fieldId ); ?>" data-type="<?php echo esc_attr( (string) $definition['type'] ); ?>" data-control-id="<?php echo esc_attr( $controlId ); ?>">
	<div class="sf-field-editor__body">
		<input type="hidden" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][id]" value="<?php echo esc_attr( $fieldId ); ?>">
		<input type="hidden" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][type]" value="<?php echo esc_attr( (string) $definition['type'] ); ?>">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Field type', 'sentinel-forms' ); ?></th>
				<td><?php echo esc_html( $field_type->label() ); ?></td>
			</tr>
			<?php if ( ! $isHidden ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-label' ); ?>"><?php esc_html_e( 'Label', 'sentinel-forms' ); ?></label></th>
					<td>
						<input type="text" class="widefat sf-input-label" id="<?php echo esc_attr( $inputIdBase . '-label' ); ?>" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][label]" value="<?php echo esc_attr( (string) ( $definition['label'] ?? '' ) ); ?>">
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( ! $isHidden && ! $isSection ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-required' ); ?>"><?php esc_html_e( 'Required', 'sentinel-forms' ); ?></label></th>
					<td>
						<input type="checkbox" id="<?php echo esc_attr( $inputIdBase . '-required' ); ?>" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][required]" value="1" <?php checked( ! empty( $definition['required'] ) ); ?>>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( ! $isHidden && ! in_array( (string) $definition['type'], [ 'consent', 'section' ], true ) ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-placeholder' ); ?>"><?php esc_html_e( 'Placeholder', 'sentinel-forms' ); ?></label></th>
					<td>
						<input type="text" class="widefat" id="<?php echo esc_attr( $inputIdBase . '-placeholder' ); ?>" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][placeholder]" value="<?php echo esc_attr( (string) ( $definition['placeholder'] ?? '' ) ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-help-text' ); ?>"><?php esc_html_e( 'Help text', 'sentinel-forms' ); ?></label></th>
					<td>
						<input type="text" class="widefat" id="<?php echo esc_attr( $inputIdBase . '-help-text' ); ?>" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][help_text]" value="<?php echo esc_attr( (string) ( $definition['help_text'] ?? '' ) ); ?>">
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( $isChoice ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-choices' ); ?>"><?php esc_html_e( 'Choices', 'sentinel-forms' ); ?></label></th>
					<td>
						<textarea class="widefat" id="<?php echo esc_attr( $inputIdBase . '-choices' ); ?>" rows="4" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][choices]" placeholder="<?php esc_attr_e( 'One per line. Optional value separated by |\ne.g. Red|red', 'sentinel-forms' ); ?>"><?php echo esc_textarea( implode( "\n", array_map( static fn ( array $choice ): string => $choice['label'] === $choice['value'] ? $choice['label'] : $choice['label'] . '|' . $choice['value'], $field_type->normalizeChoices( $definition ) ) ) ); ?></textarea>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( 'file' === (string) $definition['type'] ) : ?>
				<?php
				$uploadPolicy = is_array( $definition['upload_policy'] ?? null ) ? $definition['upload_policy'] : [];
				$allowedExt   = implode( "\n", array_map( 'strval', (array) ( $uploadPolicy['allowed_ext'] ?? \SentinelForms\Uploads\Policy\UploadPolicy::DEFAULT_EXTENSIONS ) ) );
				$maxSizeMb    = absint( $uploadPolicy['max_size'] ?? \SentinelForms\Uploads\Policy\UploadPolicy::DEFAULT_MAX_SIZE ) / 1048576;
				$maxFiles     = absint( $uploadPolicy['max_files'] ?? \SentinelForms\Uploads\Policy\UploadPolicy::DEFAULT_MAX_FILES );
				$scanEnabled  = ! array_key_exists( 'scan', $uploadPolicy ) || ! empty( $uploadPolicy['scan'] );
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-allowed-ext' ); ?>"><?php esc_html_e( 'Allowed extensions', 'sentinel-forms' ); ?></label></th>
					<td>
						<textarea class="widefat" id="<?php echo esc_attr( $inputIdBase . '-allowed-ext' ); ?>" rows="3" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][upload_policy][allowed_ext]"><?php echo esc_textarea( $allowedExt ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line, without dots.', 'sentinel-forms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-max-size' ); ?>"><?php esc_html_e( 'Max file size (MB)', 'sentinel-forms' ); ?></label></th>
					<td>
						<input type="number" min="1" step="1" class="widefat" id="<?php echo esc_attr( $inputIdBase . '-max-size' ); ?>" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][upload_policy][max_size]" value="<?php echo esc_attr( (string) $maxSizeMb ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-max-files' ); ?>"><?php esc_html_e( 'Max files per submission', 'sentinel-forms' ); ?></label></th>
					<td>
						<input type="number" min="1" step="1" class="widefat" id="<?php echo esc_attr( $inputIdBase . '-max-files' ); ?>" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][upload_policy][max_files]" value="<?php echo esc_attr( (string) $maxFiles ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-scan' ); ?>"><?php esc_html_e( 'Require malware scanning before download', 'sentinel-forms' ); ?></label></th>
					<td>
						<input type="checkbox" id="<?php echo esc_attr( $inputIdBase . '-scan' ); ?>" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][upload_policy][scan]" value="1" <?php checked( $scanEnabled ); ?>>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-scan-level' ); ?>"><?php esc_html_e( 'Scan level', 'sentinel-forms' ); ?></label></th>
					<td>
						<select class="widefat" id="<?php echo esc_attr( $inputIdBase . '-scan-level' ); ?>" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][upload_policy][scan_level]">
							<option value="basic" <?php selected( (string) ( $uploadPolicy['scan_level'] ?? 'basic' ), 'basic' ); ?>><?php esc_html_e( 'Basic (pure PHP, runs on this site)', 'sentinel-forms' ); ?></option>
							<option value="deep" <?php selected( (string) ( $uploadPolicy['scan_level'] ?? 'basic' ), 'deep' ); ?>><?php esc_html_e( 'Deep (backend queue with full malware engines)', 'sentinel-forms' ); ?></option>
						</select>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( ! $isSection ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-default' ); ?>"><?php esc_html_e( 'Default value', 'sentinel-forms' ); ?></label></th>
					<td>
						<input type="text" class="widefat" id="<?php echo esc_attr( $inputIdBase . '-default' ); ?>" name="sentinel_fields[<?php echo esc_attr( $fieldId ); ?>][default_value]" value="<?php echo esc_attr( (string) ( $definition['default_value'] ?? '' ) ); ?>">
					</td>
				</tr>
			<?php endif; ?>
		</table>
	</div>
</div>
