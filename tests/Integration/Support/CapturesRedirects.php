<?php
/**
 * wp_redirect capture for admin-post handler tests.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration\Support;

/**
 * Runs a handler that is expected to redirect and returns the target URL,
 * covering both the core wp_redirect() and the plugin's own redirect filter.
 */
trait CapturesRedirects {

	/**
	 * Invoke a handler with redirects captured.
	 *
	 * @param callable             $action      Handler to invoke.
	 * @param string               $nonceAction Nonce action for $_REQUEST ('' to skip).
	 * @param array<string, mixed> $post        POST fields to install first ([] to skip).
	 * @return string Redirect target.
	 */
	protected function captureRedirect( callable $action, string $nonceAction = '', array $post = [] ): string {
		if ( [] !== $post ) {
			$_POST = $post;
		}

		if ( '' !== $nonceAction ) {
			$_REQUEST['_wpnonce'] = wp_create_nonce( $nonceAction );
		}

		$capture = static function ( string $location ): string {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- handler-generated URL, assertions only.
			throw new RedirectedException( $location );
		};

		add_filter( 'wp_redirect', $capture );
		add_filter( 'relevant_forms_redirect', $capture );

		try {
			$action();
			$this->fail( 'The handler should redirect.' );
		} catch ( RedirectedException $redirected ) {
			return $redirected->url;
		} finally {
			remove_filter( 'wp_redirect', $capture );
			remove_filter( 'relevant_forms_redirect', $capture );

			if ( '' !== $nonceAction ) {
				unset( $_REQUEST['_wpnonce'] );
			}
		}
	}
}
