<?php
/**
 * Minimal wpforms() accessor shim (docs/wpforms-compatibility.md).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Compat\WpForms;

/**
 * Emulates the small slice of the WPForms accessor that third-party
 * consumers touch: `wpforms()->process->errors` and `->entry->get()`.
 */
final class Accessor {

	/**
	 * Process state (errors gate).
	 *
	 * @var ProcessState
	 */
	public ProcessState $process;

	/**
	 * Entry gateway.
	 *
	 * @var EntryGateway
	 */
	public EntryGateway $entry;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->process = new ProcessState();
		$this->entry   = new EntryGateway();
	}
}
