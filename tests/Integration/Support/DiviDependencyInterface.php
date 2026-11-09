<?php
/**
 * Divi 5's DependencyInterface stub: the module library dependency tree
 * calls load() on every dependency when the builder framework boots.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace ET\Builder\Framework\DependencyManagement\Interfaces;

/**
 * Divi 5's DependencyInterface (stub).
 */
interface DependencyInterface {

	/**
	 * Run the dependency's setup.
	 */
	public function load(): void;
}
