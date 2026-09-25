<?php
/**
 * Canonical form schema (doc §48).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms;

use JsonSerializable;
use RelevantForms\Forms\Fields\FieldTypeRegistry;
use RelevantForms\Forms\Visibility\VisibilityRules;
use RelevantForms\Payments\Money;

/**
 * Vendor-independent JSON representation of a form.
 */
final class FormSchema implements JsonSerializable {

	/**
	 * Current schema format version.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Confirmation mechanisms: show a message inline, redirect to a URL, or
	 * redirect to a page or post. They are mutually exclusive.
	 */
	public const CONFIRMATION_MODES = [ 'message', 'url', 'page' ];

	/**
	 * Normalized schema data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Private constructor: use named constructors.
	 *
	 * @param array<string, mixed> $data Normalized data.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Create an empty schema.
	 *
	 * @param string $name Form name.
	 */
	public static function empty( string $name = '' ): self {
		return new self(
			[
				'schema_version' => self::SCHEMA_VERSION,
				'name'           => $name,
				'currency'       => Money::DEFAULT_CURRENCY,
				'fields'         => [],
				'notifications'  => [],
				'confirmation'   => [
					'mode'          => 'message',
					'message'       => '',
					'redirect'      => '',
					'redirect_page' => 0,
				],
				'ai_policy'      => [],
				'upload_policy'  => [],
				'metadata'       => [],
			]
		);
	}

	/**
	 * Build a schema from an array, normalizing every key.
	 *
	 * @param array<string, mixed> $data Raw data.
	 */
	public static function fromArray( array $data ): self {
		$confirmation = is_array( $data['confirmation'] ?? null ) ? $data['confirmation'] : [];

		$message      = isset( $confirmation['message'] ) ? (string) $confirmation['message'] : '';
		$redirect     = isset( $confirmation['redirect'] ) ? (string) $confirmation['redirect'] : '';
		$redirectPage = isset( $confirmation['redirect_page'] ) && is_scalar( $confirmation['redirect_page'] ) ? absint( $confirmation['redirect_page'] ) : 0;

		$mode = isset( $confirmation['mode'] ) && is_string( $confirmation['mode'] ) && in_array( $confirmation['mode'], self::CONFIRMATION_MODES, true )
			? $confirmation['mode']
			: self::inferConfirmationMode( $redirect, $redirectPage );

		$fields = isset( $data['fields'] ) && is_array( $data['fields'] ) ? array_values( $data['fields'] ) : [];
		$fields = self::normalizeVisibility( $fields );

		return new self(
			[
				'schema_version' => self::SCHEMA_VERSION,
				'name'           => isset( $data['name'] ) ? (string) $data['name'] : '',
				'currency'       => Money::normalizeCurrency( $data['currency'] ?? '' ),
				'fields'         => $fields,
				'notifications'  => isset( $data['notifications'] ) && is_array( $data['notifications'] ) ? array_values( $data['notifications'] ) : [],
				'confirmation'   => [
					'mode'          => $mode,
					'message'       => $message,
					'redirect'      => $redirect,
					'redirect_page' => $redirectPage,
				],
				'ai_policy'      => is_array( $data['ai_policy'] ?? null ) ? $data['ai_policy'] : [],
				'upload_policy'  => is_array( $data['upload_policy'] ?? null ) ? $data['upload_policy'] : [],
				'metadata'       => is_array( $data['metadata'] ?? null ) ? $data['metadata'] : [],
			]
		);
	}

	/**
	 * Canonicalize per-field visibility configs on read, so stored schemas
	 * and imports always carry the normalized shape.
	 *
	 * @param array<int, mixed> $fields Raw field definitions.
	 * @return array<int, mixed>
	 */
	private static function normalizeVisibility( array $fields ): array {
		foreach ( $fields as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$visibility = VisibilityRules::normalize( $field['visibility'] ?? null );

			if ( [] === $visibility ) {
				unset( $fields[ $index ]['visibility'] );
				continue;
			}

			$fields[ $index ]['visibility'] = $visibility;
		}

		return $fields;
	}

	/**
	 * The normalized schema.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return $this->data;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Form name.
	 */
	public function name(): string {
		return (string) $this->data['name'];
	}

	/**
	 * Copy with a new name.
	 *
	 * @param string $name New form name.
	 */
	public function withName( string $name ): self {
		$copy         = $this->data;
		$copy['name'] = $name;

		return new self( $copy );
	}

	/**
	 * Payment currency (ISO 4217, lowercase). Unset or unsupported values
	 * fall back to Money::DEFAULT_CURRENCY.
	 */
	public function currency(): string {
		return Money::normalizeCurrency( $this->data['currency'] ?? '' );
	}

	/**
	 * Copy with a new payment currency.
	 *
	 * @param string $currency ISO 4217 currency code.
	 */
	public function withCurrency( string $currency ): self {
		$copy             = $this->data;
		$copy['currency'] = Money::normalizeCurrency( $currency );

		return new self( $copy );
	}

	/**
	 * Field definitions in display order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function fields(): array {
		$fields = $this->data['fields'];

		return is_array( $fields ) ? array_values( $fields ) : [];
	}

	/**
	 * Copy with new fields.
	 *
	 * @param array<int, array<string, mixed>> $fields Field definitions.
	 */
	public function withFields( array $fields ): self {
		$copy           = $this->data;
		$copy['fields'] = array_values( $fields );

		return new self( $copy );
	}

	/**
	 * Find a field by id.
	 *
	 * @param string $id Field id.
	 * @return array<string, mixed>|null
	 */
	public function field( string $id ): ?array {
		foreach ( $this->fields() as $field ) {
			if ( (string) ( $field['id'] ?? '' ) === $id ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Per-form AI policy (doc §18). Empty array means "use site defaults".
	 *
	 * @return array<string, mixed>
	 */
	public function aiPolicy(): array {
		$policy = $this->data['ai_policy'];

		return is_array( $policy ) ? $policy : [];
	}

	/**
	 * Copy with a new AI policy.
	 *
	 * @param array<string, mixed> $policy AI policy.
	 */
	public function withAiPolicy( array $policy ): self {
		$copy              = $this->data;
		$copy['ai_policy'] = $policy;

		return new self( $copy );
	}

	/**
	 * Free-form schema metadata (imports, provenance, notes).
	 *
	 * @return array<string, mixed>
	 */
	public function metadata(): array {
		$metadata = $this->data['metadata'];

		return is_array( $metadata ) ? $metadata : [];
	}

	/**
	 * Copy with new metadata.
	 *
	 * @param array<string, mixed> $metadata Metadata map.
	 */
	public function withMetadata( array $metadata ): self {
		$copy             = $this->data;
		$copy['metadata'] = $metadata;

		return new self( $copy );
	}

	/**
	 * Notification recipient emails.
	 *
	 * @return array<int, string>
	 */
	public function notificationEmails(): array {
		$emails = $this->data['notifications'];

		if ( ! is_array( $emails ) ) {
			return [];
		}

		$result = [];
		foreach ( $emails as $email ) {
			$email = trim( (string) $email );
			if ( $email !== '' ) {
				$result[] = $email;
			}
		}

		return array_values( array_unique( $result ) );
	}

	/**
	 * Copy with new notification emails.
	 *
	 * @param array<int, string> $emails Recipient addresses.
	 */
	public function withNotifications( array $emails ): self {
		$copy                  = $this->data;
		$copy['notifications'] = array_values( $emails );

		return new self( $copy );
	}

	/**
	 * Confirmation message shown after a successful submission.
	 */
	public function confirmationMessage(): string {
		$message = $this->data['confirmation']['message'] ?? '';

		return (string) $message;
	}

	/**
	 * Optional redirect URL after submission.
	 */
	public function redirectUrl(): string {
		return (string) ( $this->data['confirmation']['redirect'] ?? '' );
	}

	/**
	 * Immutable redirect anchor: ID of the page or post to redirect to
	 * (0 = none). The permalink is resolved on submission, so the redirect
	 * keeps working when the target's slug changes.
	 */
	public function redirectPageId(): int {
		return (int) ( $this->data['confirmation']['redirect_page'] ?? 0 );
	}

	/**
	 * Confirmation mechanism in use: 'message', 'url' or 'page'
	 * (see CONFIRMATION_MODES). Legacy data without a mode is inferred from
	 * the stored redirect values.
	 */
	public function confirmationMode(): string {
		$mode = $this->data['confirmation']['mode'] ?? '';

		if ( is_string( $mode ) && in_array( $mode, self::CONFIRMATION_MODES, true ) ) {
			return $mode;
		}

		return self::inferConfirmationMode( $this->redirectUrl(), $this->redirectPageId() );
	}

	/**
	 * Infer the confirmation mode from legacy redirect values.
	 *
	 * @param string $redirect     Redirect URL.
	 * @param int    $redirectPage Redirect anchor ID.
	 */
	private static function inferConfirmationMode( string $redirect, int $redirectPage ): string {
		if ( $redirectPage > 0 ) {
			return 'page';
		}

		if ( '' !== $redirect ) {
			return 'url';
		}

		return 'message';
	}

	/**
	 * Copy with new confirmation settings.
	 *
	 * @param string      $message      Confirmation message.
	 * @param string      $redirect     Redirect URL (may be empty).
	 * @param int         $redirectPage Redirect anchor page/post ID (0 = none).
	 * @param string|null $mode         Confirmation mode; inferred from the values when omitted or invalid.
	 */
	public function withConfirmation( string $message, string $redirect, int $redirectPage = 0, ?string $mode = null ): self {
		if ( null === $mode || ! in_array( $mode, self::CONFIRMATION_MODES, true ) ) {
			$mode = self::inferConfirmationMode( $redirect, $redirectPage );
		}

		$copy                 = $this->data;
		$copy['confirmation'] = [
			'mode'          => $mode,
			'message'       => $message,
			'redirect'      => $redirect,
			'redirect_page' => $redirectPage,
		];

		return new self( $copy );
	}

	/**
	 * Validate schema integrity. Returns human readable problems.
	 *
	 * @return array<int, string>
	 */
	public function validate(): array {
		$errors = [];
		$ids    = [];

		foreach ( $this->fields() as $field ) {
			if ( ! is_array( $field ) ) {
				$errors[] = __( 'A field definition is malformed.', 'relevant-forms' );
				continue;
			}

			$id   = (string) ( $field['id'] ?? '' );
			$type = (string) ( $field['type'] ?? '' );

			if ( $id === '' ) {
				$errors[] = __( 'A field is missing its id.', 'relevant-forms' );
			} else {
				$ids[] = $id;
			}

			if ( ! FieldTypeRegistry::has( $type ) ) {
				$errors[] = sprintf(
					/* translators: %s: field type */
					__( 'Unknown field type "%s".', 'relevant-forms' ),
					$type
				);
			}

			if ( ! in_array( $type, [ 'hidden', 'section', 'pagebreak', 'entry-preview' ], true ) && '' === trim( (string) ( $field['label'] ?? '' ) ) ) {
				$errors[] = sprintf(
					/* translators: %s: field id */
					__( 'Field "%s" is missing a label.', 'relevant-forms' ),
					$id
				);
			}
		}

		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			$errors[] = __( 'Duplicate field ids.', 'relevant-forms' );
		}

		foreach ( $this->notificationEmails() as $email ) {
			if ( filter_var( $email, FILTER_VALIDATE_EMAIL ) === false ) {
				$errors[] = sprintf(
					/* translators: %s: email address */
					__( 'Invalid notification email "%s".', 'relevant-forms' ),
					$email
				);
			}
		}

		return $errors;
	}
}
