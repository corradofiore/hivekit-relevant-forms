<?php
/**
 * Shared admin-ajax guard and error envelope.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Support;

/**
 * Small helpers for the plugin's admin-ajax handlers: the capability
 * guard and the {code, message} error envelope consumed by admin.js.
 */
final class Ajax {

	/**
	 * End the request unless the current user may manage the site.
	 *
	 * @param string $message Message returned with the 403 response.
	 */
	public static function ensureManager( string $message ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => $message ], 403 );
		}
	}

	/**
	 * Send a {code, message} error response and end the request.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status code.
	 */
	public static function fail( string $code, string $message, int $status ): void {
		wp_send_json_error(
			[
				'code'    => $code,
				'message' => $message,
			],
			$status
		);
	}
}
