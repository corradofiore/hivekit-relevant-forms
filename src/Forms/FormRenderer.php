<?php
/**
 * Front-end form rendering (doc §9).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms;

use RelevantForms\Entries\Entry;
use RelevantForms\Entries\Resume\ResumeRequest;
use RelevantForms\Forms\Fields\ChoiceFieldType;
use RelevantForms\Forms\Fields\FieldTypeRegistry;
use RelevantForms\Forms\Fields\PaymentField;
use RelevantForms\Forms\Fields\TextareaField;
use RelevantForms\Forms\Visibility\VisibilityRules;
use RelevantForms\Payments\Money;
use RelevantForms\Validation\EmailDeliverability;

/**
 * Renders published forms as accessible markup and registers the shortcode.
 */
final class FormRenderer {

	/**
	 * Register the shortcode and front-end assets.
	 */
	public static function register(): void {
		add_shortcode( 'relevant_form', [ self::class, 'shortcode' ] );
	}

	/**
	 * Shortcode callback: [relevant_form id="123"].
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public static function shortcode( array|string $atts = [] ): string {
		$atts = shortcode_atts( [ 'id' => 0 ], is_array( $atts ) ? $atts : [], 'relevant_form' );

		return self::render( (int) $atts['id'] );
	}

	/**
	 * Render a published form by ID.
	 *
	 * @param int                          $formId Form ID.
	 * @param array<string, string>|null   $errors Validation errors keyed by field id.
	 * @param array<string, mixed>|null    $values Previously submitted values.
	 */
	public static function render( int $formId, ?array $errors = null, ?array $values = null ): string {
		$form = ( new FormRepository() )->findPublished( $formId );

		if ( null === $form ) {
			return '';
		}

		return self::renderForm( $form, $errors, $values );
	}

	/**
	 * Render a loaded form entity. Errors/values default to the front-end
	 * submission feedback of the current request (if any), so a failed
	 * in-page POST shows validation errors inline.
	 *
	 * @param Form                         $form    Form to render.
	 * @param array<string, string>|null   $errors  Validation errors keyed by field id.
	 * @param array<string, mixed>|null    $values  Previously submitted values.
	 * @param bool                         $preview Render for the admin form preview: the
	 *                                              markup matches the live form but carries no
	 *                                              submission wiring (no upload tokens, no Save
	 *                                              and Resume, no feedback from the request) and
	 *                                              uses its own control ids.
	 */
	public static function renderForm( Form $form, ?array $errors = null, ?array $values = null, bool $preview = false ): string {
		// Front-end integrations hook this to render alongside the live form;
		// the editor preview leaves them out rather than running visitor-side
		// code inside wp-admin.
		if ( ! $preview ) {
			do_action( 'relevant_forms_before_form_output', $form );
		}

		$hasFeedback = null !== $errors || null !== $values;

		if ( ! $preview && null === $errors ) {
			$errors = SubmissionFeedback::errors( (int) $form->id );
		}

		if ( ! $preview && null === $values ) {
			$values = SubmissionFeedback::values( (int) $form->id );
		}

		// A preview has no submission behind it: the fields render their
		// stored defaults, without errors or entered values.
		if ( $preview ) {
			$errors ??= [];
			$values ??= [];
		}

		// Save and Resume: resolve a resume link and restore the saved values
		// — unless this request already carries submission feedback (a failed
		// POST is the most recent input and wins). The preview never saves or
		// restores progress.
		$resumeConfig = $form->schema->saveResume();
		$saveResumeOn = ! $preview && (bool) apply_filters( 'relevant_forms_save_resume_enabled', $resumeConfig['enabled'], $form );
		$resume       = $saveResumeOn ? ResumeRequest::resolve( $form ) : [ 'status' => 'none' ];

		if ( ! $hasFeedback && 'resolved' === $resume['status'] && is_array( $resume['values'] ?? null ) ) {
			$values = $resume['values'];
		}

		if ( ! $preview ) {
			// The admin form preview lives on a screen that already loads the
			// front-end stylesheet, so enqueuing it again would print a copy.
			wp_enqueue_style( 'relevant-forms' );
		}

		// The first field is always shown: rules can only depend on fields
		// above, and nothing sits above the first field. Stored rules
		// (legacy or imported) are ignored.
		$definitions = VisibilityRules::withoutFirstFieldRules( $form->schema->fields() );
		$visibility  = VisibilityRules::evaluate( $definitions, $values );
		$definitions = self::prepareDefinitions( $definitions, $visibility, $values, $form->schema->currency() );
		$pages       = self::groupPages( $definitions, $visibility );

		$hasFileField       = false;
		$hasLimitedTextarea = false;
		$hasVisibility      = false;
		$hasPaymentFields   = false;
		$hasEntryPreview    = false;
		$hasEmailField      = false;

		foreach ( $definitions as $definition ) {
			$fieldType = (string) ( $definition['type'] ?? '' );

			if ( 'file' === $fieldType ) {
				$hasFileField = true;
			}

			if ( 'email' === $fieldType ) {
				$hasEmailField = true;
			}

			if ( 'textarea' === $fieldType && TextareaField::limit( $definition ) > 0 ) {
				$hasLimitedTextarea = true;
			}

			if ( VisibilityRules::hasVisibility( $definition ) ) {
				$hasVisibility = true;
			}

			if ( FieldTypeRegistry::get( $fieldType ) instanceof PaymentField ) {
				$hasPaymentFields = true;
			}

			if ( 'entry-preview' === $fieldType ) {
				$hasEntryPreview = true;
			}
		}

		// Enqueued before the other feature scripts: the browser-draft restore
		// must run before the wizard boots so it can reopen the saved page.
		if ( $saveResumeOn ) {
			wp_enqueue_script( 'relevant-forms-save-resume' );
		}

		if ( $hasFileField ) {
			wp_enqueue_script( 'relevant-forms-upload' );
		}

		if ( $hasLimitedTextarea ) {
			wp_enqueue_script( 'relevant-forms-counter' );
		}

		if ( $hasVisibility ) {
			wp_enqueue_script( 'relevant-forms-conditional' );
		}

		if ( $hasPaymentFields ) {
			wp_enqueue_script( 'relevant-forms-total' );
		}

		if ( null !== $pages ) {
			wp_enqueue_script( 'relevant-forms-pages' );
		}

		if ( $hasEntryPreview ) {
			wp_enqueue_script( 'relevant-forms-preview' );
		}

		// Real-time domain check for email fields.
		if ( $hasEmailField && EmailDeliverability::enabled() ) {
			wp_enqueue_script( 'relevant-forms-email-check' );
		}

		$output   = [];
		$output[] = $preview ? '' : self::successMessage( $form );
		$output[] = $preview ? '' : self::paymentNotice( $form );
		// Empty action: the browser posts to this very page, where the
		// front-end submission handler renders errors inline and redirects
		// after success — user pages never post to wp-admin.
		$pagesAttr = null === $pages
			? ''
			: sprintf(
				' data-rf-page-prev="%1$s" data-rf-page-next="%2$s" data-rf-page-step="%3$s"',
				esc_attr__( 'Previous', 'relevant-forms' ),
				esc_attr__( 'Next', 'relevant-forms' ),
				/* translators: 1: current page number, 2: total number of pages. */
				esc_attr__( 'Step %1$d of %2$d', 'relevant-forms' )
			);
		// The preview carries no upload token: the front-end upload script only
		// binds to forms that have one, so nothing can be uploaded from it.
		$uploadAttr = $preview
			? ''
			: sprintf( ' data-upload-nonce="%s"', esc_attr( wp_create_nonce( 'relevant_upload_' . $form->id ) ) );
		$resumeAttr = '';

		if ( $saveResumeOn ) {
			$resumeAttr = sprintf(
				' data-rf-save-resume-nonce="%s" data-rf-resume-expiry="%d" data-rf-drafts="%d"',
				esc_attr( wp_create_nonce( 'relevant_save_resume_' . $form->id ) ),
				(int) $resumeConfig['expiry_days'],
				empty( $resumeConfig['drafts_in_browser'] ) ? 0 : 1
			);

			if ( 'resolved' === $resume['status'] ) {
				$resumeAttr .= sprintf( ' data-rf-resume-token="%s"', esc_attr( (string) ( $resume['token'] ?? '' ) ) );

				$savedPage = $resume['entry'] instanceof Entry ? (int) $resume['entry']->resumePage : 0;

				if ( $savedPage > 1 ) {
					$resumeAttr .= sprintf( ' data-rf-initial-page="%d"', $savedPage );
				}
			}
		}

		// Distinct control ids in the preview: the editor renders per-field
		// samples of the same fields with the real form id, and duplicate ids
		// would break their labels.
		$renderId = $preview ? $form->id . '-preview' : (string) $form->id;

		$output[] = sprintf(
			'<form class="%1$s" data-form-id="%2$d"%3$s%4$s%5$s%6$s method="post" action="" novalidate>',
			$preview ? 'relevant-forms relevant-forms--preview' : 'relevant-forms',
			$form->id,
			$uploadAttr,
			$pagesAttr,
			$resumeAttr,
			$preview ? ' data-rf-form-preview="1"' : ''
		);
		$output[] = '<input type="hidden" name="action" value="relevant_forms_submit">';
		$output[] = sprintf( '<input type="hidden" name="_relevant_form_id" value="%d">', $form->id );
		$output[] = sprintf( '<input type="hidden" name="_relevant_started_at" value="%s">', esc_attr( (string) microtime( true ) ) );

		if ( 'resolved' === $resume['status'] ) {
			// Completion conversion (SubmissionHandler): the final submission
			// updates the partial entry instead of creating a second one.
			$output[] = sprintf( '<input type="hidden" name="_relevant_resume_token" value="%s">', esc_attr( (string) ( $resume['token'] ?? '' ) ) );
		}

		$output[] = wp_nonce_field( 'relevant_form_' . $form->id, '_relevant_form_nonce', true, false );
		$output[] = self::honeypot();

		$currency = $form->schema->currency();

		if ( null === $pages ) {
			foreach ( $definitions as $definition ) {
				$markup = self::renderField( $definition, $currency, $values, $errors, $visibility, $renderId, $preview );

				if ( '' !== $markup ) {
					$output[] = $markup;
				}
			}

			$output[] = sprintf(
				'<button type="submit" class="relevant-forms-submit wp-element-button">%s</button>',
				esc_html__( 'Submit', 'relevant-forms' )
			);
		} else {
			$total = count( $pages );
			$break = FieldTypeRegistry::get( 'pagebreak' );

			foreach ( $pages as $index => $page ) {
				$output[] = sprintf( '<div class="relevant-forms-page" data-rf-page="%d">', $index + 1 );

				// The break marker opens every page after the first; it is a
				// plain divider and disappears together with its page.
				if ( $index > 0 && null !== $break && null !== $page['break'] ) {
					$output[] = $break->render( (array) $page['break'], null, null, $renderId );
				}
				$output[] = sprintf(
					'<p class="relevant-forms-page-indicator" data-rf-page-indicator>%s</p>',
					esc_html(
						sprintf(
							/* translators: 1: current page number, 2: total number of pages. */
							__( 'Step %1$d of %2$d', 'relevant-forms' ),
							$index + 1,
							$total
						)
					)
				);

				$title = (string) ( $page['title'] ?? '' );

				if ( '' !== $title ) {
					$output[] = sprintf( '<h3 class="relevant-forms-page-title">%s</h3>', esc_html( $title ) );
				}

				foreach ( $page['fields'] as $definition ) {
					$markup = self::renderField( $definition, $currency, $values, $errors, $visibility, $renderId, $preview );

					if ( '' !== $markup ) {
						$output[] = $markup;
					}
				}

				$output[] = '</div>';
			}

			$output[] = '<div class="relevant-forms-submit-row" data-rf-submit-row>';
			$output[] = sprintf(
				'<button type="submit" class="relevant-forms-submit wp-element-button">%s</button>',
				esc_html__( 'Submit', 'relevant-forms' )
			);
			$output[] = '</div>';
		}

		$output[] = self::resumeNotice( $resume, $saveResumeOn );

		if ( $saveResumeOn ) {
			$output[] = self::saveResumeRow( $resumeConfig );
		}

		$output[] = '</form>';

		return implode( "\n", $output );
	}

	/**
	 * Resume notice: restored-progress confirmation, or the invalid-link
	 * warning when a stale token was presented.
	 *
	 * @param array<string, mixed> $resume       Resume resolution result.
	 * @param bool                 $saveResumeOn Whether Save and Resume is active.
	 */
	private static function resumeNotice( array $resume, bool $saveResumeOn ): string {
		$status = (string) ( $resume['status'] ?? 'none' );

		if ( ! $saveResumeOn || 'none' === $status ) {
			return '';
		}

		if ( 'invalid' === $status ) {
			return sprintf(
				'<div class="relevant-forms-notice relevant-forms-notice--warning" role="status">%s</div>',
				esc_html__( 'This resume link is no longer valid — please fill in the form again.', 'relevant-forms' )
			);
		}

		$entry    = $resume['entry'] ?? null;
		$savedAt  = $entry instanceof Entry ? get_date_from_gmt( (string) $entry->updatedAt, 'M j, Y H:i' ) : '';
		$restored = '' !== $savedAt
			? sprintf(
				/* translators: %s: date and time the progress was saved */
				__( 'Welcome back — we restored the progress you saved on %s.', 'relevant-forms' ),
				$savedAt
			)
			: __( 'Welcome back — we restored your saved progress.', 'relevant-forms' );

		return sprintf(
			'<div class="relevant-forms-notice relevant-forms-notice--info" data-rf-resume-notice role="status">%s <button type="button" class="relevant-forms-notice__action" data-rf-resume-discard>%s</button></div>',
			esc_html( $restored ),
			esc_html__( 'Start fresh', 'relevant-forms' )
		);
	}

	/**
	 * Save and Resume row: the button plus status lines. Rendered hidden;
	 * without JavaScript the button stays hidden and nothing is stored.
	 *
	 * @param array<string, mixed> $config Save and Resume configuration.
	 */
	private static function saveResumeRow( array $config ): string {
		$label = trim( (string) ( $config['label'] ?? '' ) );

		if ( '' === $label ) {
			$label = __( 'Save and Resume', 'relevant-forms' );
		}

		return implode(
			"\n",
			[
				'<div class="relevant-forms-save-resume" data-rf-save-resume-row hidden>',
				sprintf(
					'<button type="button" class="relevant-forms-save-resume__button" data-rf-save-resume-button>%s</button>',
					esc_html( $label )
				),
				'<p class="relevant-forms-save-resume__status" data-rf-save-resume-status role="status" aria-live="polite" hidden></p>',
				'<p class="relevant-forms-save-resume__draft" data-rf-draft-indicator role="status" aria-live="polite" hidden></p>',
				'</div>',
			]
		);
	}

	/**
	 * Render one field with its visibility state applied.
	 *
	 * @param array<string, mixed>   $definition Field definition.
	 * @param string                 $currency   Form currency.
	 * @param array<string, mixed>   $values     Submitted values keyed by field id.
	 * @param array<string, string>  $errors     Validation errors keyed by field id.
	 * @param array<string, bool>    $visibility Visibility map keyed by field id.
	 * @param string                 $formId     Form id for control ids.
	 * @param bool                   $preview    Whether this is the admin preview.
	 */
	private static function renderField( array $definition, string $currency, array $values, array $errors, array $visibility, string $formId, bool $preview = false ): string {
		// Payment fields format and validate with the form currency.
		$definition['currency'] = $currency;

		$type = FieldTypeRegistry::get( (string) ( $definition['type'] ?? '' ) );

		if ( null === $type ) {
			return '';
		}

		$fieldId = (string) ( $definition['id'] ?? '' );
		$value   = array_key_exists( $fieldId, $values ) ? $values[ $fieldId ] : ( $definition['default_value'] ?? '' );
		$error   = isset( $errors[ $fieldId ] ) ? (string) $errors[ $fieldId ] : null;

		if ( isset( $visibility[ $fieldId ] ) ) {
			$hidden = false === $visibility[ $fieldId ];

			// A hidden required field must not block a no-JS submission;
			// the script restores the flag when the field is revealed.
			if ( $hidden ) {
				$definition['visibility_required'] = ! empty( $definition['required'] );
				$definition['required']            = false;
			}

			$definition['visibility_hidden'] = $hidden;
		}

		$previewNote = '';

		if ( $preview && 'html' === (string) ( $definition['type'] ?? '' ) ) {
			// Raw author code never runs inside wp-admin: the preview renders
			// the block through the same filter the save path applies to users
			// without unfiltered_html, and says what it left out.
			$raw                = (string) ( $definition['code'] ?? '' );
			$definition['code'] = wp_kses_post( $raw );

			if ( $definition['code'] !== $raw ) {
				$previewNote = sprintf(
					'<p class="relevant-forms-preview__note">%s</p>',
					esc_html__( 'Custom HTML block: scripts and unsafe markup are not run in the preview — they appear on the live form.', 'relevant-forms' )
				);
			}
		}

		return $type->render( $definition, $value, $error, $formId ) . $previewNote;
	}

	/**
	 * Group definitions into pages at page breaks.
	 *
	 * Returns null when the form has no visible page breaks, so ordinary
	 * forms keep the original single-page markup. Hidden page breaks do not
	 * start a page (their fields flow on); pages that end up empty merge
	 * away.
	 *
	 * @param array<int, array<string, mixed>> $definitions Field definitions in order.
	 * @param array<string, bool>              $visibility  Visibility map keyed by field id.
	 * @return array<int, array{break: array<string, mixed>|null, title: string, fields: array<int, array<string, mixed>>}>|null
	 */
	private static function groupPages( array $definitions, array $visibility ): ?array {
		$hasBreak = false;

		foreach ( $definitions as $definition ) {
			if ( 'pagebreak' !== (string) ( $definition['type'] ?? '' ) ) {
				continue;
			}

			$fieldId = (string) ( $definition['id'] ?? '' );

			if ( isset( $visibility[ $fieldId ] ) && false === $visibility[ $fieldId ] ) {
				continue;
			}

			$hasBreak = true;
			break;
		}

		if ( ! $hasBreak ) {
			return null;
		}

		$raw = [
			[
				'break'  => null,
				'fields' => [],
			],
		];

		foreach ( $definitions as $definition ) {
			if ( 'pagebreak' === (string) ( $definition['type'] ?? '' ) ) {
				$fieldId = (string) ( $definition['id'] ?? '' );

				if ( isset( $visibility[ $fieldId ] ) && false === $visibility[ $fieldId ] ) {
					continue;
				}

				$raw[] = [
					'break'  => $definition,
					'fields' => [],
				];

				continue;
			}

			$raw[ count( $raw ) - 1 ]['fields'][] = $definition;
		}

		$pages = [];

		foreach ( $raw as $page ) {
			if ( [] === $page['fields'] ) {
				continue;
			}

			$page['title'] = trim( (string) ( $page['break']['label'] ?? '' ) );
			$pages[]       = $page;
		}

		// A single page (for example a trailing break with no fields after it)
		// is not a wizard; keep the plain single-page markup.
		if ( count( $pages ) < 2 ) {
			return null;
		}

		return $pages;
	}

	/**
	 * Inject payloads into definitions before rendering.
	 *
	 * Entry preview rows are computed here (never earlier) so both the plain
	 * and the paginated paths receive them. Definitions without preview
	 * fields pass through untouched.
	 *
	 * @param array<int, array<string, mixed>> $definitions Field definitions in order.
	 * @param array<string, bool>              $visibility  Visibility map keyed by field id.
	 * @param array<string, mixed>             $values      Entered values keyed by field id.
	 * @param string                           $currency    Form currency.
	 * @return array<int, array<string, mixed>>
	 */
	private static function prepareDefinitions( array $definitions, array $visibility, array $values, string $currency ): array {
		$hasPreview = false;

		foreach ( $definitions as $definition ) {
			if ( 'entry-preview' === (string) ( $definition['type'] ?? '' ) ) {
				$hasPreview = true;
				break;
			}
		}

		if ( ! $hasPreview ) {
			return $definitions;
		}

		foreach ( $definitions as $index => $definition ) {
			if ( 'entry-preview' !== (string) ( $definition['type'] ?? '' ) ) {
				continue;
			}

			$definitions[ $index ]['preview_rows'] = self::previewRows( $definitions, $index, $visibility, $values, $currency );
		}

		return $definitions;
	}

	/**
	 * Rows for one entry preview field: the data fields entered before it.
	 *
	 * Empty values and fields hidden by conditional logic keep their row but
	 * start hidden, so the front-end script can reveal them without a server
	 * round trip. Fields between two previews are only reported once, on the
	 * later preview, mirroring WPForms.
	 *
	 * @param array<int, array<string, mixed>> $definitions Field definitions in order.
	 * @param int                              $index       Preview field index.
	 * @param array<string, bool>              $visibility  Visibility map keyed by field id.
	 * @param array<string, mixed>             $values      Entered values keyed by field id.
	 * @param string                           $currency    Form currency.
	 * @return array<int, array{id: string, label: string, value: string, hidden_by_rule: bool}>
	 */
	private static function previewRows( array $definitions, int $index, array $visibility, array $values, string $currency ): array {
		$start = 0;

		for ( $i = $index - 1; $i >= 0; --$i ) {
			if ( 'entry-preview' === (string) ( $definitions[ $i ]['type'] ?? '' ) ) {
				$start = $i + 1;
				break;
			}
		}

		$rows = [];

		foreach ( array_slice( $definitions, $start, $index - $start ) as $definition ) {
			$type  = (string) ( $definition['type'] ?? '' );
			$id    = (string) ( $definition['id'] ?? '' );
			$label = trim( (string) ( $definition['label'] ?? '' ) );

			if ( in_array( $type, [ 'section', 'pagebreak', 'entry-preview', 'content', 'html' ], true ) || '' === $id || '' === $label ) {
				continue;
			}

			$rows[] = [
				'id'             => $id,
				'label'          => $label,
				'value'          => self::previewValue( $type, $definition, $values[ $id ] ?? '', $currency ),
				'hidden_by_rule' => false === ( $visibility[ $id ] ?? true ),
			];
		}

		return $rows;
	}

	/**
	 * Human-readable preview text for one entered value.
	 *
	 * Choice values resolve to their labels, priced choices to the same
	 * "Label (price)" text the visitor picked, and payment amounts to the
	 * form currency. The live total is left empty on purpose: the front-end
	 * script reads it from the total element.
	 *
	 * @param string               $type       Field type.
	 * @param array<string, mixed> $definition Field definition.
	 * @param mixed                $raw        Entered value.
	 * @param string               $currency   Form currency.
	 */
	private static function previewValue( string $type, array $definition, mixed $raw, string $currency ): string {
		if ( 'consent' === $type ) {
			return ! empty( $raw ) ? __( 'Yes', 'relevant-forms' ) : '';
		}

		if ( 'payment-total' === $type ) {
			return '';
		}

		if ( 'payment-single' === $type ) {
			$amount = is_scalar( $raw ) ? trim( (string) $raw ) : '';
			$minor  = '' !== $amount ? Money::toMinor( $amount, $currency ) : null;

			return null !== $minor ? Money::format( $minor, $currency ) : $amount;
		}

		$isPayment = in_array( $type, [ 'payment-select', 'payment-multiple', 'payment-checkbox' ], true );
		$isChoice  = $isPayment || in_array( $type, [ 'select', 'radio', 'checkbox' ], true );

		if ( is_array( $raw ) ) {
			$labels = [];

			foreach ( $raw as $item ) {
				if ( ! is_scalar( $item ) ) {
					continue;
				}

				$labels[] = $isChoice ? self::previewChoiceLabel( $definition, (string) $item, $isPayment, $currency ) : (string) $item;
			}

			return implode( ', ', $labels );
		}

		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$value = trim( (string) $raw );

		if ( '' === $value ) {
			return '';
		}

		return $isChoice ? self::previewChoiceLabel( $definition, $value, $isPayment, $currency ) : $value;
	}

	/**
	 * Label for a submitted choice value (with its price when priced).
	 *
	 * Priced payment fields submit the option position, everything else
	 * submits the option value (falling back to the label, like the entry
	 * storage).
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $value      Submitted value (or position).
	 * @param bool                 $priced     Whether this is a priced payment field.
	 * @param string               $currency   Form currency.
	 */
	private static function previewChoiceLabel( array $definition, string $value, bool $priced, string $currency ): string {
		$type = FieldTypeRegistry::get( (string) ( $definition['type'] ?? '' ) );

		if ( ! $type instanceof ChoiceFieldType ) {
			return $value;
		}

		$choices = $type->normalizeChoices( $definition );

		if ( $priced ) {
			$index = (int) $value;

			if ( ! isset( $choices[ $index ] ) ) {
				return $value;
			}

			$label = $choices[ $index ]['label'];
			$minor = Money::toMinor( $choices[ $index ]['value'], $currency );

			return null !== $minor && $minor > 0 ? $label . ' (' . Money::format( $minor, $currency ) . ')' : $label;
		}

		foreach ( $choices as $choice ) {
			if ( (string) $choice['value'] === $value ) {
				return $choice['label'];
			}
		}

		return $value;
	}

	/**
	 * Confirmation message when the URL carries a success marker for this form.
	 */
	private static function successMessage( Form $form ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success marker set by our own redirect.
		if ( ! isset( $_GET['relevant_form_success'] ) || (int) $_GET['relevant_form_success'] !== $form->id ) {
			return '';
		}

		$message = $form->schema->confirmationMessage();

		if ( '' === $message ) {
			$message = __( 'Thank you for your message. We will be in touch soon.', 'relevant-forms' );
		}

		return sprintf( '<p class="relevant-forms-success" role="status">%s</p>', esc_html( $message ) );
	}

	/**
	 * Notice when a payment attempt came back without completing (the
	 * checkout cancel URL or an expired session redirect carries this
	 * marker).
	 */
	private static function paymentNotice( Form $form ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only marker set by our own redirect.
		if ( ! isset( $_GET['relevant_payment_failed'] ) || (int) $_GET['relevant_payment_failed'] !== $form->id ) {
			return '';
		}

		return sprintf(
			'<p class="relevant-forms-payment-notice" role="alert">%s</p>',
			esc_html__( 'The payment was not completed. You can try again below.', 'relevant-forms' )
		);
	}

	/**
	 * Visually hidden honeypot field (doc §12). Bots filling it are dropped.
	 */
	private static function honeypot(): string {
		return '<div class="relevant-forms-hp" aria-hidden="true">'
			. '<label for="relevant-forms-hp">' . esc_html__( 'Website', 'relevant-forms' ) . '</label>'
			. '<input type="text" id="relevant-forms-hp" name="_relevant_hp" tabindex="-1" autocomplete="off">'
			. '</div>';
	}
}
