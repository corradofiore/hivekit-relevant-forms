<?php
/**
 * Managed priority SMS alerts (Phase 5, doc §20–25).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Alerts;

use RelevantForms\Ai\Classification;
use RelevantForms\Cloud\CloudClient;
use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Forms\Form;
use RelevantForms\Support\Logger;

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
	private const OPTION = 'relevant_forms_settings';

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
	 *
	 * @param Form|null $form Form entity, when the alert is form-scoped.
	 */
	public function isEnabled( ?Form $form = null ): bool {
		if ( ! CloudConfig::isConfigured() ) {
			return false;
		}

		if ( ! $this->connection->capabilityEnabled( CloudConnection::CAP_SMS ) ) {
			return false;
		}

		return '' !== $this->recipient( $form );
	}

	/**
	 * Recipient phone number: the constant wins, then the form's verified
	 * per-form number (when the editor option is on), then the site default.
	 *
	 * @param Form|null $form Form entity, when the alert is form-scoped.
	 */
	public function recipient( ?Form $form = null ): string {
		$constant = defined( 'RELEVANT_SMS_RECIPIENT' ) ? (string) RELEVANT_SMS_RECIPIENT : '';

		if ( '' !== trim( $constant ) ) {
			return trim( $constant );
		}

		if ( null !== $form ) {
			$policy = $form->schema->aiPolicy();
			$phone  = trim( (string) ( $policy['sms_phone'] ?? '' ) );

			if ( '' !== $phone && ! empty( $policy['sms_alert'] ) ) {
				return $phone;
			}
		}

		$settings = (array) get_option( self::OPTION, [] );

		return trim( (string) ( $settings['sms_recipient'] ?? '' ) );
	}

	/**
	 * Whether this classification deserves an interruption.
	 *
	 * @param Classification $classification Validated classification.
	 * @param Form|null      $form           Form entity, when the alert is form-scoped.
	 */
	public function shouldSend( Classification $classification, ?Form $form = null ): bool {
		if ( ! $this->isEnabled( $form ) ) {
			return false;
		}

		$threshold = (int) apply_filters( 'relevant_forms_sms_attention_threshold', self::DEFAULT_THRESHOLD );

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
			'recipient' => $this->recipient( $form ),
			'alert'     => [
				'headline'        => mb_substr( $form->name, 0, 120 ),
				'summary'         => mb_substr( '' !== $summary ? $summary : __( 'Priority submission received.', 'relevant-forms' ), 0, 500 ),
				'entry_url'       => admin_url( 'admin.php?page=relevant-entry&entry_id=' . $entryId ),
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
		$payload = (array) apply_filters( 'relevant_forms_sms_alert_payload', $payload, $form, $classification );

		$client = new CloudClient( CloudConfig::apiBase(), CloudConfig::key() );
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
