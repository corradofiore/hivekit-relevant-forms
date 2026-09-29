<?php
/**
 * Notification sender identity ("From" header).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Notifications;

use RelevantForms\Forms\Form;

/**
 * Builds the From header from the per-form sender settings. The name and
 * the address are used together or not at all; without both, WordPress
 * applies its default identity.
 */
final class SenderIdentity {

	/**
	 * From header for the form's emails ('' when the form has none).
	 *
	 * @param Form $form Form entity.
	 */
	public static function fromHeader( Form $form ): string {
		$sender = $form->schema->sender();
		$name   = trim( $sender['name'] );
		$email  = trim( $sender['email'] );

		if ( '' === $name || ! is_email( $email ) ) {
			return '';
		}

		return sprintf( 'From: %s <%s>', $name, $email );
	}
}
