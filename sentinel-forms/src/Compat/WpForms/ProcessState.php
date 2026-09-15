<?php
/**
 * wpforms_process errors gate (docs/wpforms-compatibility.md).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Compat\WpForms;

/**
 * Mutable error bag for the wpforms_process gate. Consumers write
 * `wpforms()->process->errors[ $field_id ] = 'message'` to halt a
 * submission, exactly like they do with WPForms.
 */
final class ProcessState {

	/**
	 * Errors keyed by field id (string or [header|footer => message]).
	 *
	 * @var array<string, mixed>
	 */
	public array $errors = [];
}
