<?php
/**
 * wpforms()->entry->get() equivalent (docs/wpforms-compatibility.md).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Compat\WpForms;

use RelevantForms\Entries\Entry;
use RelevantForms\Entries\EntryRepository;
use RelevantForms\Forms\FormRepository;

/**
 * Loads Relevant entries in the WPForms entry shape for consumers.
 */
final class EntryGateway {

	/**
	 * Load an entry in WPForms entry shape.
	 *
	 * @param int $entryId Entry ID.
	 * @return object|null
	 */
	public function get( int $entryId ): ?object {
		$entry = ( new EntryRepository() )->find( $entryId );

		if ( ! $entry instanceof Entry ) {
			return null;
		}

		$form  = ( new FormRepository() )->find( $entry->formId );
		$value = null !== $form ? ShapeTranslator::fields( $form, $entry->meta ) : [];

		return (object) [
			'entry_id' => $entryId,
			'form_id'  => $entry->formId,
			'status'   => $entry->status,
			'fields'   => wp_json_encode( $value ),
			'date'     => $entry->createdAt,
		];
	}
}
