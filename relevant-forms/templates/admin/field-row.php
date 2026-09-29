<?php
/**
 * Single field editor panel (right column of the fields editor).
 *
 * The panel prepends the field type as plain text and ends with a sample
 * rendering of the field, produced by the same renderer as the front end.
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

$fieldId        = (string) $definition['id'];
$isChoice       = in_array( (string) $definition['type'], [ 'select', 'radio', 'checkbox' ], true );
$isHidden       = 'hidden' === (string) $definition['type'];
$isSection      = 'section' === (string) $definition['type'];
$isPagebreak    = 'pagebreak' === (string) $definition['type'];
$isEntryPreview = 'entry-preview' === (string) $definition['type'];
$isContent      = 'content' === (string) $definition['type'];
$isHtml         = 'html' === (string) $definition['type'];

$labelHeading = __( 'Label', 'relevant-forms' );

if ( $isSection ) {
	$labelHeading = __( 'Section header', 'relevant-forms' );
} elseif ( $isPagebreak ) {
	$labelHeading = __( 'Page title', 'relevant-forms' );
} elseif ( $isEntryPreview || $isContent ) {
	$labelHeading = __( 'Heading (optional)', 'relevant-forms' );
} elseif ( $isHtml ) {
	$labelHeading = __( 'Name (editor only)', 'relevant-forms' );
}

$fieldType       = (string) $definition['type'];
$isFile          = 'file' === $fieldType;
$isPricedPayment = in_array( $fieldType, [ 'payment-select', 'payment-multiple', 'payment-checkbox' ], true );
$isPaymentSingle = 'payment-single' === $fieldType;
$isPaymentTotal  = 'payment-total' === $fieldType;

// Amounts on payment rows show the form currency, so the editor does not
// depend on the settings tab to know what the numbers mean.
$currencyCode = strtoupper( \RelevantForms\Payments\Money::normalizeCurrency( $form->schema->currency() ) );

// Base DOM id of the preview controls, used by admin.js to update the
// preview (label, required, placeholder, help text...) while typing.
$controlId = 'relevant-field-' . (string) $form->id . '-' . $fieldId;

// Id prefix of the editor inputs (headers + fields two-column layout).
$inputIdBase = 'rf-field-' . $fieldId;
?>
<div class="rf-field-editor" data-for="<?php echo esc_attr( $fieldId ); ?>" data-type="<?php echo esc_attr( (string) $definition['type'] ); ?>" data-control-id="<?php echo esc_attr( $controlId ); ?>">
	<div class="rf-field-editor__body">
		<input type="hidden" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][id]" value="<?php echo esc_attr( $fieldId ); ?>">
		<input type="hidden" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][type]" value="<?php echo esc_attr( (string) $definition['type'] ); ?>">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Field type', 'relevant-forms' ); ?></th>
				<td><?php echo esc_html( $field_type->label() ); ?></td>
			</tr>
			<?php if ( ! $isHidden ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-label' ); ?>"><?php echo esc_html( $labelHeading ); ?></label></th>
					<td>
						<input type="text" class="widefat rf-input-label" id="<?php echo esc_attr( $inputIdBase . '-label' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][label]" value="<?php echo esc_attr( (string) ( $definition['label'] ?? '' ) ); ?>">
						<?php if ( $isPagebreak ) : ?>
							<p class="description"><?php esc_html_e( 'Shown above the step indicator. Leave empty for an untitled page.', 'relevant-forms' ); ?></p>
						<?php elseif ( $isEntryPreview ) : ?>
							<p class="description"><?php esc_html_e( 'Shown above the preview. Leave empty for no heading.', 'relevant-forms' ); ?></p>
						<?php elseif ( $isContent ) : ?>
							<p class="description"><?php esc_html_e( 'Shown above the content block. Leave empty for no heading.', 'relevant-forms' ); ?></p>
						<?php elseif ( $isHtml ) : ?>
							<p class="description"><?php esc_html_e( 'Used to tell fields apart in the editor; never shown on the form.', 'relevant-forms' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( $isEntryPreview ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Preview notice', 'relevant-forms' ); ?></th>
					<td>
						<label><input type="checkbox" id="<?php echo esc_attr( $inputIdBase . '-notice-enable' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][notice_enable]" value="1" <?php checked( ! empty( $definition['notice_enable'] ) ); ?>> <?php esc_html_e( 'Display a notice above the previewed answers.', 'relevant-forms' ); ?></label>
						<p><textarea class="widefat" id="<?php echo esc_attr( $inputIdBase . '-notice' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][notice]" rows="3" placeholder="<?php echo esc_attr( (string) \RelevantForms\Forms\Fields\EntryPreviewField::notice( [ 'notice_enable' => true ] ) ); ?>"><?php echo esc_textarea( (string) ( $definition['notice'] ?? '' ) ); ?></textarea></p>
						<p class="description"><?php esc_html_e( 'Leave empty to use the default notice.', 'relevant-forms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-style' ); ?>"><?php esc_html_e( 'Style', 'relevant-forms' ); ?></label></th>
					<td>
						<?php $previewStyle = (string) ( $definition['style'] ?? 'basic' ); ?>
						<select id="<?php echo esc_attr( $inputIdBase . '-style' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][style]">
							<option value="basic"<?php selected( $previewStyle, 'basic' ); ?>><?php esc_html_e( 'Basic', 'relevant-forms' ); ?></option>
							<option value="compact"<?php selected( $previewStyle, 'compact' ); ?>><?php esc_html_e( 'Compact', 'relevant-forms' ); ?></option>
							<option value="table"<?php selected( $previewStyle, 'table' ); ?>><?php esc_html_e( 'Table', 'relevant-forms' ); ?></option>
							<option value="table_compact"<?php selected( $previewStyle, 'table_compact' ); ?>><?php esc_html_e( 'Table, compact', 'relevant-forms' ); ?></option>
						</select>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( $isContent ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-content' ); ?>"><?php esc_html_e( 'Content', 'relevant-forms' ); ?></label></th>
					<td>
						<textarea class="widefat" id="<?php echo esc_attr( $inputIdBase . '-content' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][content]" rows="8"><?php echo esc_textarea( (string) ( $definition['content'] ?? '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Basic HTML is allowed: headings, bold, links and lists. Line breaks become paragraphs.', 'relevant-forms' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( $isHtml ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-code' ); ?>"><?php esc_html_e( 'Code', 'relevant-forms' ); ?></label></th>
					<td>
						<textarea class="widefat code" id="<?php echo esc_attr( $inputIdBase . '-code' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][code]" rows="10" spellcheck="false"><?php echo esc_textarea( (string) ( $definition['code'] ?? '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Rendered as authored on the form — no escaping is applied. Do not paste code you do not trust.', 'relevant-forms' ); ?></p>
						<?php if ( ! current_user_can( 'unfiltered_html' ) ) : ?>
							<p class="description"><?php esc_html_e( 'Your role cannot save raw code: scripts, styles and embedded frames will be removed when you save this form.', 'relevant-forms' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( ! $isHidden && ! $isSection && ! $isPagebreak && ! $isEntryPreview && ! $isContent && ! $isHtml && ! $isPaymentTotal ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-required' ); ?>"><?php esc_html_e( 'Required', 'relevant-forms' ); ?></label></th>
					<td>
						<input type="checkbox" id="<?php echo esc_attr( $inputIdBase . '-required' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][required]" value="1" <?php checked( ! empty( $definition['required'] ) ); ?>>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( in_array( (string) $definition['type'], [ 'text', 'textarea' ], true ) ) : ?>
				<?php
				$maxLengthDefault = 'text' === (string) $definition['type']
					? \RelevantForms\Forms\Fields\TextField::MAX_LENGTH
					: \RelevantForms\Forms\Fields\TextareaField::MAX_LENGTH;
				$maxLength        = array_key_exists( 'max_length', $definition ) ? (int) $definition['max_length'] : $maxLengthDefault;
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-max-length' ); ?>"><?php esc_html_e( 'Maximum characters', 'relevant-forms' ); ?></label></th>
					<td>
						<input type="number" min="0" step="1" class="rf-input-max-length" id="<?php echo esc_attr( $inputIdBase . '-max-length' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][max_length]" value="<?php echo esc_attr( (string) $maxLength ); ?>">
						<p class="description"><?php esc_html_e( '0 for no limit.', 'relevant-forms' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( $isPaymentSingle ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-amount' ); ?>"><?php echo esc_html( sprintf( /* translators: %s: form currency code, e.g. USD. */ __( 'Amount (%s)', 'relevant-forms' ), $currencyCode ) ); ?></label></th>
					<td>
						<input type="text" inputmode="decimal" class="rf-input-payment-amount" id="<?php echo esc_attr( $inputIdBase . '-amount' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][amount]" value="<?php echo esc_attr( (string) ( $definition['amount'] ?? '0.00' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Ignored when the visitor enters the amount.', 'relevant-forms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-user-amount' ); ?>"><?php esc_html_e( 'Visitor enters the amount', 'relevant-forms' ); ?></label></th>
					<td>
						<input type="checkbox" id="<?php echo esc_attr( $inputIdBase . '-user-amount' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][user_amount]" value="1" <?php checked( ! empty( $definition['user_amount'] ) ); ?>>
						<p class="description"><?php esc_html_e( 'Donation style: the visitor chooses how much to pay.', 'relevant-forms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-min-amount' ); ?>"><?php echo esc_html( sprintf( /* translators: %s: form currency code, e.g. USD. */ __( 'Minimum amount (%s)', 'relevant-forms' ), $currencyCode ) ); ?></label></th>
					<td>
						<input type="text" inputmode="decimal" id="<?php echo esc_attr( $inputIdBase . '-min-amount' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][min_amount]" value="<?php echo esc_attr( (string) ( $definition['min_amount'] ?? '' ) ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-max-amount' ); ?>"><?php echo esc_html( sprintf( /* translators: %s: form currency code, e.g. USD. */ __( 'Maximum amount (%s)', 'relevant-forms' ), $currencyCode ) ); ?></label></th>
					<td>
						<input type="text" inputmode="decimal" id="<?php echo esc_attr( $inputIdBase . '-max-amount' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][max_amount]" value="<?php echo esc_attr( (string) ( $definition['max_amount'] ?? '' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Used when the visitor enters the amount. Leave empty for no custom limits.', 'relevant-forms' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( ! $isHidden && ! in_array( (string) $definition['type'], [ 'consent', 'section', 'pagebreak', 'entry-preview', 'content', 'html', 'payment-total' ], true ) ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-placeholder' ); ?>"><?php esc_html_e( 'Placeholder', 'relevant-forms' ); ?></label></th>
					<td>
						<input type="text" class="widefat" id="<?php echo esc_attr( $inputIdBase . '-placeholder' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][placeholder]" value="<?php echo esc_attr( (string) ( $definition['placeholder'] ?? '' ) ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-help-text' ); ?>"><?php esc_html_e( 'Help text', 'relevant-forms' ); ?></label></th>
					<td>
						<input type="text" class="widefat" id="<?php echo esc_attr( $inputIdBase . '-help-text' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][help_text]" value="<?php echo esc_attr( (string) ( $definition['help_text'] ?? '' ) ); ?>">
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( $isChoice || $isPricedPayment ) : ?>
				<?php
				$choiceLines = array_map(
					static function ( array $choice ) use ( $isPricedPayment ): string {
						if ( $choice['label'] === $choice['value'] ) {
							return $choice['label'];
						}

						// Regular choices read "Value|Label"; priced choices keep
						// the item first: "Item|Price".
						return $isPricedPayment
							? $choice['label'] . '|' . $choice['value']
							: $choice['value'] . '|' . $choice['label'];
					},
					$field_type->normalizeChoices( $definition )
				);
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-choices' ); ?>"><?php esc_html_e( 'Choices', 'relevant-forms' ); ?></label></th>
					<td>
						<textarea class="widefat" id="<?php echo esc_attr( $inputIdBase . '-choices' ); ?>" rows="4" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][choices]"><?php echo esc_textarea( implode( "\n", $choiceLines ) ); ?></textarea>
						<?php if ( $isPricedPayment ) : ?>
							<p class="description"><?php echo esc_html( sprintf( /* translators: %s: form currency code, e.g. USD. */ __( 'One per line. The price follows the item after a pipe (|), e.g. T-shirt|25.00. Prices are in %s.', 'relevant-forms' ), $currencyCode ) ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'One value per line in "value|label" format, e.g. not_sure|Not sure', 'relevant-forms' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( $isFile ) : ?>
				<?php
				$uploadPolicy = is_array( $definition['upload_policy'] ?? null ) ? $definition['upload_policy'] : [];
				$allowedExt   = implode( "\n", array_map( 'strval', (array) ( $uploadPolicy['allowed_ext'] ?? \RelevantForms\Uploads\Policy\UploadPolicy::DEFAULT_EXTENSIONS ) ) );
				$maxSizeMb    = absint( $uploadPolicy['max_size'] ?? \RelevantForms\Uploads\Policy\UploadPolicy::DEFAULT_MAX_SIZE ) / 1048576;
				$maxFiles     = absint( $uploadPolicy['max_files'] ?? \RelevantForms\Uploads\Policy\UploadPolicy::DEFAULT_MAX_FILES );
				$scanEnabled  = ! array_key_exists( 'scan', $uploadPolicy ) || ! empty( $uploadPolicy['scan'] );
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-allowed-ext' ); ?>"><?php esc_html_e( 'Allowed extensions', 'relevant-forms' ); ?></label></th>
					<td>
						<textarea class="widefat" id="<?php echo esc_attr( $inputIdBase . '-allowed-ext' ); ?>" rows="3" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][upload_policy][allowed_ext]"><?php echo esc_textarea( $allowedExt ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line, without dots.', 'relevant-forms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-max-size' ); ?>"><?php esc_html_e( 'Max file size (MB)', 'relevant-forms' ); ?></label></th>
					<td>
						<input type="number" min="1" step="1" class="widefat" id="<?php echo esc_attr( $inputIdBase . '-max-size' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][upload_policy][max_size]" value="<?php echo esc_attr( (string) $maxSizeMb ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-max-files' ); ?>"><?php esc_html_e( 'Max files per submission', 'relevant-forms' ); ?></label></th>
					<td>
						<input type="number" min="1" step="1" class="widefat" id="<?php echo esc_attr( $inputIdBase . '-max-files' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][upload_policy][max_files]" value="<?php echo esc_attr( (string) $maxFiles ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-scan' ); ?>"><?php esc_html_e( 'Require malware scanning before download', 'relevant-forms' ); ?></label></th>
					<td>
						<input type="checkbox" id="<?php echo esc_attr( $inputIdBase . '-scan' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][upload_policy][scan]" value="1" <?php checked( $scanEnabled ); ?>>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-scan-level' ); ?>"><?php esc_html_e( 'Scan level', 'relevant-forms' ); ?></label></th>
					<td>
						<select class="widefat" id="<?php echo esc_attr( $inputIdBase . '-scan-level' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][upload_policy][scan_level]">
							<option value="basic" <?php selected( (string) ( $uploadPolicy['scan_level'] ?? 'basic' ), 'basic' ); ?>><?php esc_html_e( 'Basic (pure PHP, runs on this site)', 'relevant-forms' ); ?></option>
							<option value="deep" <?php selected( (string) ( $uploadPolicy['scan_level'] ?? 'basic' ), 'deep' ); ?>><?php esc_html_e( 'Deep (backend queue with full malware engines)', 'relevant-forms' ); ?></option>
						</select>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( ! $isSection && ! $isPagebreak && ! $isEntryPreview && ! $isContent && ! $isHtml && ! $isPaymentTotal && ! $isPricedPayment && ! $isFile ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $inputIdBase . '-default' ); ?>"><?php esc_html_e( 'Default value', 'relevant-forms' ); ?></label></th>
					<td>
						<input type="text" class="widefat" id="<?php echo esc_attr( $inputIdBase . '-default' ); ?>" name="relevant_fields[<?php echo esc_attr( $fieldId ); ?>][default_value]" value="<?php echo esc_attr( (string) ( $definition['default_value'] ?? '' ) ); ?>">
					</td>
				</tr>
			<?php endif; ?>
		</table>
	</div>
</div>
