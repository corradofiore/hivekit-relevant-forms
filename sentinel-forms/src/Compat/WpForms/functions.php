<?php
/**
 * wpforms() shim (Phase 7.5). Loaded only while the compat layer is
 * active and WPForms itself is absent (docs/wpforms-compatibility.md).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wpforms' ) ) {
	/**
	 * Minimal WPForms accessor: ->process->errors and ->entry->get().
	 */
	function wpforms(): \SentinelForms\Compat\WpForms\Accessor {
		return \SentinelForms\Compat\WpForms\CompatLayer::accessor();
	}
}
