<?php
/**
 * Site administrator email resolution.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Support;

/**
 * Lists the valid email addresses of every site administrator, so the
 * notification fallback and the form editor caption always agree.
 */
final class SiteAdmins {

	/**
	 * Valid, unique administrator emails: the configured site
	 * administration address (admin_email) first — so the pre-existing
	 * recipient always stays included — then every administrator account
	 * in login order.
	 *
	 * @return array<int, string>
	 */
	public static function emails(): array {
		$emails = [];

		$primary = trim( (string) get_option( 'admin_email' ) );

		if ( is_email( $primary ) ) {
			$emails[] = $primary;
		}

		foreach ( get_users( [ 'role' => 'administrator' ] ) as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}

			$email = trim( (string) $user->user_email );

			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}

		return array_values( array_unique( $emails ) );
	}
}
