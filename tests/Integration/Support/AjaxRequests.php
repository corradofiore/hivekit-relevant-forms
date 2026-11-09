<?php
/**
 * wp_send_json() lifecycle simulation for admin-ajax handler tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration\Support;

/**
 * Invokes AJAX handlers the way admin-ajax.php would, returning the JSON
 * envelope they send before terminating the request.
 */
final class AjaxRequests {

	/**
	 * Run one AJAX handler and return its decoded response body.
	 *
	 * The wp_send_json() helper only routes through wp_die() in a real
	 * admin-ajax request (wp_doing_ajax()); the test framework overrides
	 * only the default wp_die() handler — both are simulated here.
	 *
	 * @param callable $handler Handler to invoke.
	 * @return array<string, mixed> Decoded response body.
	 * @throws \RuntimeException When the handler terminates without wp_send_json().
	 */
	public static function dispatch( callable $handler ): array {
		$doing_ajax = static function (): bool {
			return true;
		};

		$ajax_die = static function (): callable {
			return static function (): void {
				throw new \WPDieException();
			};
		};

		add_filter( 'wp_doing_ajax', $doing_ajax );
		add_filter( 'wp_die_ajax_handler', $ajax_die );

		$terminated = false;

		ob_start();

		try {
			$handler();
		} catch ( \WPDieException ) {
			$terminated = true;
		} finally {
			$body = json_decode( (string) ob_get_clean(), true );

			remove_filter( 'wp_doing_ajax', $doing_ajax );
			remove_filter( 'wp_die_ajax_handler', $ajax_die );
		}

		if ( ! $terminated ) {
			throw new \RuntimeException( 'wp_send_json() should terminate the request.' );
		}

		return is_array( $body ) ? $body : [];
	}
}
