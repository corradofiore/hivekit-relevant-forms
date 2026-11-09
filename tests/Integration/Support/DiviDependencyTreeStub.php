<?php
/**
 * Stub of Divi's module library dependency tree that records what gets
 * registered on it.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration\Support;

/**
 * Records dependencies added to Divi's module library dependency tree.
 */
final class DiviDependencyTreeStub {

	/**
	 * Dependencies added so far.
	 *
	 * @var array<int, object>
	 */
	public array $dependencies = [];

	/**
	 * Record one dependency.
	 *
	 * @param object $dependency Dependency instance.
	 */
	public function add_dependency( object $dependency ): void {
		$this->dependencies[] = $dependency;
	}
}
