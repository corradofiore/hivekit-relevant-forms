<?php
/**
 * Parsed WPForms form (doc §46–47).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Import;

/**
 * Vendor structure parsed from a WPForms form record (post_content JSON).
 * Pure data + analysis; WordPress access lives in the scanner/importer.
 */
final class WpFormsForm {

	/**
	 * Compatibility statuses.
	 */
	public const STATUS_COMPATIBLE = 'compatible';

	public const STATUS_REVIEW = 'review';

	public const STATUS_UNSUPPORTED = 'unsupported';

	/**
	 * Constructor.
	 *
	 * @param int                  $sourceId      WPForms post ID.
	 * @param string               $title         Form title.
	 * @param array<int, array<string, mixed>> $fields Raw WPForms fields.
	 * @param array<string, mixed> $settings      Form settings.
	 */
	private function __construct(
		public readonly int $sourceId,
		public readonly string $title,
		public readonly array $fields,
		public readonly array $settings,
	) {}

	/**
	 * Parse a form record. Returns null when the payload is not a form.
	 */
	public static function fromJson( int $sourceId, string $title, string $json ): ?self {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		return self::fromArray( $sourceId, $title, $data );
	}

	/**
	 * Parse normalized form data.
	 *
	 * @param array<string, mixed> $data Decoded form payload.
	 */
	public static function fromArray( int $sourceId, string $title, array $data ): ?self {
		$rawFields = $data['fields'] ?? null;

		if ( ! is_array( $rawFields ) ) {
			return null;
		}

		$fields = [];

		foreach ( $rawFields as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$field['id'] = (string) ( $field['id'] ?? $key );

			$fields[] = $field;
		}

		return new self(
			$sourceId,
			'' !== trim( $title ) ? $title : (string) ( $data['settings']['form_title'] ?? 'Form ' . $sourceId ),
			$fields,
			is_array( $data['settings'] ?? null ) ? $data['settings'] : [],
		);
	}

	/**
	 * Fields mapped to the Sentinel schema.
	 *
	 * @return array<int, array{definition: array<string, mixed>, notes: array<int, string>}>
	 */
	public function mappedFields(): array {
		$mapped = [];

		foreach ( $this->fields as $field ) {
			$result = WpFormsFieldMapper::map( $field );

			if ( null !== $result ) {
				$mapped[] = $result;
			}
		}

		return $mapped;
	}

	/**
	 * Fields that cannot be imported, with reasons.
	 *
	 * @return array<int, array{label: string, type: string, reason: string}>
	 */
	public function skippedFields(): array {
		$skipped = [];

		foreach ( $this->fields as $field ) {
			if ( null !== WpFormsFieldMapper::map( $field ) ) {
				continue;
			}

			$type = (string) ( $field['type'] ?? '' );

			$skipped[] = [
				'label'  => (string) ( $field['label'] ?? $field['id'] ?? '' ),
				'type'   => $type,
				'reason' => __( 'Unsupported field type (not part of the initial importer scope).', 'sentinel-forms' ),
			];
		}

		return $skipped;
	}

	/**
	 * Notification recipient emails (smart tags fall back to the admin).
	 *
	 * @return array{emails: array<int, string>, notes: array<int, string>}
	 */
	public function notifications(): array {
		$notifications = $this->settings['notifications'] ?? [];

		if ( ! is_array( $notifications ) ) {
			return [
				'emails' => [],
				'notes'  => [],
			];
		}

		$emails = [];
		$notes  = [];

		foreach ( $notifications as $notification ) {
			if ( ! is_array( $notification ) ) {
				continue;
			}

			foreach ( explode( ',', (string) ( $notification['email'] ?? '' ) ) as $candidate ) {
				$candidate = trim( $candidate );

				if ( '' === $candidate ) {
					continue;
				}

				if ( false !== filter_var( $candidate, FILTER_VALIDATE_EMAIL ) ) {
					$emails[] = $candidate;
					continue;
				}

				// Smart tags like {admin_email} or {field_id="0"}: keep the
				// default site administrator recipient and explain why.
				if ( str_contains( $candidate, '{' ) && ! in_array( 'smart_tags', $notes, true ) ) {
					$notes[] = __( 'Notification recipients using WPForms smart tags fall back to the site administrator email.', 'sentinel-forms' );
					$notes[] = 'smart_tags';
				}
			}
		}

		$notes = array_values( array_filter( $notes, static fn ( string $note ): bool => 'smart_tags' !== $note ) );

		return [
			'emails' => array_values( array_unique( $emails ) ),
			'notes'  => $notes,
		];
	}

	/**
	 * Confirmation message and redirect.
	 *
	 * @return array{message: string, redirect: string}
	 */
	public function confirmation(): array {
		$confirmations = $this->settings['confirmations'] ?? [];

		if ( ! is_array( $confirmations ) ) {
			return [
				'message'  => '',
				'redirect' => '',
			];
		}

		$first = reset( $confirmations );

		if ( ! is_array( $first ) ) {
			return [
				'message'  => '',
				'redirect' => '',
			];
		}

		$type = (string) ( $first['type'] ?? 'message' );

		return [
			'message'  => 'message' === $type ? (string) ( $first['message'] ?? '' ) : '',
			'redirect' => in_array( $type, [ 'redirect', 'page' ], true ) ? (string) ( $first['redirect'] ?? '' ) : '',
		];
	}

	/**
	 * Compatibility analysis for the scanner output (doc §46).
	 *
	 * @return array{status: string, issues: array<int, string>, uploads: bool, conditionals: bool, integrations: bool}
	 */
	public function analysis(): array {
		$skipped      = $this->skippedFields();
		$mapped       = $this->mappedFields();
		$issues       = [];
		$uploads      = false;
		$conditionals = false;

		foreach ( $this->fields as $field ) {
			if ( ! empty( $field['conditionals'] ) ) {
				$conditionals = true;
			}
		}

		foreach ( $mapped as $entry ) {
			if ( 'file' === ( $entry['definition']['type'] ?? '' ) ) {
				$uploads = true;
			}
		}

		foreach ( $skipped as $field ) {
			$issues[] = sprintf(
				/* translators: 1: field label, 2: WPForms field type */
				__( '"%1$s" (%2$s) is not supported and will be skipped.', 'sentinel-forms' ),
				$field['label'],
				$field['type']
			);
		}

		if ( $conditionals ) {
			$issues[] = __( 'Conditional logic is not imported.', 'sentinel-forms' );
		}

		$integrations = $this->usesIntegrations();

		if ( $integrations ) {
			$issues[] = __( 'Payment or marketing integrations are not imported.', 'sentinel-forms' );
		}

		$status = self::STATUS_COMPATIBLE;

		if ( $integrations ) {
			$status = self::STATUS_UNSUPPORTED;
		} elseif ( [] !== $skipped || $conditionals ) {
			$status = self::STATUS_REVIEW;
		}

		if ( [] === $this->fields ) {
			$status   = self::STATUS_UNSUPPORTED;
			$issues[] = __( 'The form has no fields.', 'sentinel-forms' );
		}

		return [
			'status'       => $status,
			'issues'       => $issues,
			'uploads'      => $uploads,
			'conditionals' => $conditionals,
			'integrations' => $integrations,
		];
	}

	/**
	 * Whether the form references payment/marketing integrations.
	 */
	private function usesIntegrations(): bool {
		$settings = $this->settings;

		if ( ! empty( $settings['payments'] ) || ! empty( $settings['providers'] ) ) {
			return true;
		}

		foreach ( $this->fields as $field ) {
			if ( str_starts_with( strtolower( (string) ( $field['type'] ?? '' ) ), 'payment' ) ) {
				return true;
			}
		}

		return false;
	}
}
