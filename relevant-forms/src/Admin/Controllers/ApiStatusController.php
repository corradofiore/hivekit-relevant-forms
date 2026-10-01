<?php
/**
 * API status test for the diagnostics screen (admin-ajax).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Controllers;

use RelevantForms\Cloud\CloudClient;
use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Support\Ajax;

/**
 * Probes the Relevant Cloud health endpoint and reports the result.
 */
final class ApiStatusController {

	/**
	 * AJAX action (also the nonce action).
	 */
	public const ACTION = 'relevant_forms_test_api_status';

	/**
	 * Register the AJAX handler.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'test' ] );
	}

	/**
	 * AJAX: call the health endpoint and report status plus round-trip time.
	 */
	public static function test(): void {
		Ajax::ensureManager( __( 'You do not have permission to test the API connection.', 'relevant-forms' ) );

		check_ajax_referer( self::ACTION );

		$started = microtime( true );

		$result = ( new CloudClient( CloudConfig::apiBase(), CloudConfig::key() ) )->health();

		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( 200 !== $result['status'] || null === $result['data'] ) {
			$error = (string) ( $result['error']['message'] ?? '' );

			if ( '' === $error ) {
				$error = sprintf(
					/* translators: %d: HTTP status code. */
					__( 'the service responded with HTTP status %d', 'relevant-forms' ),
					$result['status']
				);
			}

			Ajax::fail(
				'unreachable',
				sprintf(
					/* translators: %s: failure detail. */
					__( 'The API is unreachable — %s.', 'relevant-forms' ),
					$error
				),
				502
			);
		}

		$data    = $result['data'];
		$app     = (string) ( $data['app'] ?? __( 'Relevant Cloud', 'relevant-forms' ) );
		$version = (string) ( $data['version'] ?? '' );

		$message = '' !== $version
			? sprintf(
				/* translators: 1: service name, 2: service version, 3: round-trip time in milliseconds. */
				__( 'The API is online — %1$s %2$s responded in %3$d ms.', 'relevant-forms' ),
				$app,
				$version,
				$latency
			)
			: sprintf(
				/* translators: 1: service name, 2: round-trip time in milliseconds. */
				__( 'The API is online — %1$s responded in %2$d ms.', 'relevant-forms' ),
				$app,
				$latency
			);

		wp_send_json_success(
			[
				'app'        => $app,
				'version'    => $version,
				'latency_ms' => $latency,
				'message'    => $message,
			]
		);
	}
}
