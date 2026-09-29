<?php
/**
 * pre_wp_mail capture for notification tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration\Support;

/**
 * Captures outgoing mail in a class property so tests don't repeat the
 * filter-closure boilerplate.
 */
trait CapturesMail {

	/**
	 * Captured wp_mail() argument arrays, oldest first.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $sent_mail = [];

	/**
	 * Capture every mail sent from now on.
	 */
	protected function captureMail(): void {
		add_filter(
			'pre_wp_mail',
			function ( ?bool $shortCircuit, array $args ): bool {
				$this->sent_mail[] = $args;

				return true;
			},
			10,
			2
		);
	}

	/**
	 * Stop capturing mail.
	 */
	protected function stopCapturingMail(): void {
		remove_all_filters( 'pre_wp_mail' );
	}

	/**
	 * Make every send fail, as a broken mailer would.
	 */
	protected function failMail(): void {
		add_filter( 'pre_wp_mail', static fn (): bool => false );
	}
}
