<?php
/**
 * Core-side classification client (Phase 4, v2 §4).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Ai;

use RelevantForms\Cloud\CloudClient;
use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Forms\Form;
use RelevantForms\Support\Logger;

/**
 * Sends redacted submissions to the Cloud for semantic classification.
 * Gated on explicit consent: without an enabled capability no HTTP
 * request is made (v2 §7–8, hard constraint).
 */
final class AiClassifier {

	/**
	 * Constructor.
	 *
	 * @param CloudConnection|null $connection Consent state.
	 * @param ContextBuilder|null  $contextBuilder Payload builder.
	 */
	public function __construct(
		?CloudConnection $connection = null,
		?ContextBuilder $contextBuilder = null,
	) {
		$this->connection = $connection ?? new CloudConnection();
		$this->builder    = $contextBuilder ?? new ContextBuilder();
	}

	/**
	 * Consent state.
	 *
	 * @var CloudConnection
	 */
	private CloudConnection $connection;

	/**
	 * Payload builder.
	 *
	 * @var ContextBuilder
	 */
	private ContextBuilder $builder;

	/**
	 * Whether classification should run for this form.
	 */
	public function isEnabled( Form $form ): bool {
		if ( ! CloudConfig::isConfigured() ) {
			return false;
		}

		if ( ! $this->connection->capabilityEnabled( CloudConnection::CAP_AI ) ) {
			return false;
		}

		return false !== ( $form->schema->aiPolicy()['enabled'] ?? true );
	}

	/**
	 * Classify a submission. Returns null when disabled or on failure —
	 * a failed classification never affects the stored entry (v2 §4).
	 *
	 * @param Form                 $form       Form entity.
	 * @param array<string, mixed> $values     Sanitized values.
	 * @param array<string, mixed> $protection Local anti-spam evaluation.
	 */
	public function classify( Form $form, array $values, array $protection ): ?Classification {
		return $this->classifyOutcome( $form, $values, $protection )['classification'];
	}

	/**
	 * Classify a submission, reporting whether a failure is worth
	 * repeating and carrying back the context that was sent, so the caller
	 * can queue the request instead of dropping the work.
	 *
	 * @param Form                 $form       Form entity.
	 * @param array<string, mixed> $values     Sanitized values.
	 * @param array<string, mixed> $protection Local anti-spam evaluation.
	 * @return array{classification: Classification|null, context: array<string, mixed>, retryable: bool, error: string}
	 */
	public function classifyOutcome( Form $form, array $values, array $protection ): array {
		if ( ! $this->isEnabled( $form ) ) {
			return [
				'classification' => null,
				'context'        => [],
				'retryable'      => false,
				'error'          => 'disabled',
			];
		}

		$context = $this->builder->build( $form, $values, $protection );

		$client  = new CloudClient( CloudConfig::apiBase(), CloudConfig::key() );
		$outcome = $client->classifyOutcome( $context );

		if ( ! $outcome['ok'] || ! is_array( $outcome['data']['classification'] ?? null ) ) {
			Logger::warning(
				'Classification request failed',
				[
					'form_id'   => $form->id,
					'code'      => $outcome['code'],
					'error'     => $outcome['error'],
					'retryable' => $outcome['retryable'],
				]
			);

			return [
				'classification' => null,
				'context'        => $context,
				'retryable'      => (bool) $outcome['retryable'],
				'error'          => (string) $outcome['error'],
			];
		}

		return [
			'classification' => Classification::fromArray( (array) $outcome['data']['classification'] ),
			'context'        => $context,
			'retryable'      => false,
			'error'          => '',
		];
	}
}
