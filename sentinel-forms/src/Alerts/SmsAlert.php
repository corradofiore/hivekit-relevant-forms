<?php
/**
 * Managed priority SMS alerts (Phase 5, doc §20–25).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Alerts;

use SentinelForms\Ai\Classification;
use SentinelForms\Cloud\CloudClient;
use SentinelForms\Cloud\CloudConfig;
use SentinelForms\Cloud\CloudConnection;
use SentinelForms\Forms\Form;
use SentinelForms\Support\Logger;

/**
 * Interrupts the operator only when the message deserves it: sends the
 * minimal alert payload (AI summary + secure entry link) to the Cloud,
 * which renders and routes the SMS (v2 §4).
 *
 * Consent-gated: without the explicitly enabled capability no HTTP
 * request is made (v2 §7–8, hard constraint).
 */
final class SmsAlert {

	/**
	 * Default attention threshold (doc §20: >= 85 → priority + SMS).
	 */
	public const DEFAULT_THRESHOLD = 85;

	/**
	 * Settings option key.
	 */
	private const OPTION = 'sentinel_forms_settings';

	/**
	 * Constructor.
	 *
	 * @param CloudConnection|null $connection Consent state.
	 */
	public function __construct(
		?CloudConnection $connection = null,
	) {
		$this->connection = $connection ?? new CloudConnection();
	}

	/**
	 * Consent state.
	 *
	 * @var CloudConnection
	 */
	private CloudConnection $connection;

	/**
	 * Whether SMS alerts should run at all.
	 */
	public function isEnabled(): bool {
		if ( ! CloudConfig::isConfigured() ) {
			return false;
		}

		if ( ! $this->connection->capabilityEnabled( CloudConnection::CAP_SMS ) ) {
			return false;
		}

		return '' !== $this->recipient();
	}

	/**
	 * Recipient phone number (constant first, then settings).
	 */
	public function recipient(): string {
		$constant = defined( 'SENTINEL_SMS_RECIPIENT' ) ? (string) SENTINEL_SMS_RECIPIENT : '';

		if ( '' !== trim( $constant ) ) {
			return trim( $constant );
		}

		$settings = (array) get_option( self::OPTION, [] );

		return trim( (string) ( $settings['sms_recipient'] ?? '' ) );
	}

	/**
	 * Whether this classification deserves an interruption.
	 */
	public function shouldSend( Classification $classification ): bool {
		if ( ! $this->isEnabled() ) {
			return false;
		}

		$threshold = (int) apply_filters( 'sentinel_forms_sms_attention_threshold', self::DEFAULT_THRESHOLD );

		return $classification->attentionScore >= $threshold;
	}

	/**
	 * Send the alert. Returns the Cloud delivery data, or null on failure
	 * — a failed SMS never affects the stored entry (doc §57).
	 *
	 * @param Form           $form           Form entity.
	 * @param int            $entryId        Stored entry ID.
	 * @param Classification $classification Validated classification.
	 * @return array<string, mixed>|null
	 */
	public function send( Form $form, int $entryId, Classification $classification ): ?array {
		$summary = trim( $classification->summary );

		$payload = [
			'recipient' => $this->recipient(),
			'alert'     => [
				'headline'        => mb_substr( $form->name, 0, 120 ),
				'summary'         => mb_substr( '' !== $summary ? $summary : __( 'Priority submission received.', 'sentinel-forms' ), 0, 500 ),
				'entry_url'       => admin_url( 'admin.php?page=sentinel-entry&entry_id=' . $entryId ),
				'reason_code'     => $classification->reasonCode,
				'attention_score' => $classification->attentionScore,
			],
		];

		/**
		 * Filter the managed SMS alert payload before transmission.
		 *
		 * @param array<string, mixed> $payload        Alert payload.
		 * @param Form                 $form           Form entity.
		 * @param Classification       $classification Validated classification.
		 */
		$payload = (array) apply_filters( 'sentinel_forms_sms_alert_payload', $payload, $form, $classification );

		$client = new CloudClient( CloudConfig::url(), CloudConfig::key() );
		$data   = $client->sendSmsAlert( $payload );

		if ( null === $data ) {
			Logger::warning(
				'Managed SMS request failed',
				[
					'form_id'  => $form->id,
					'entry_id' => $entryId,
				]
			);

			return null;
		}

		return $data;
	}
}
