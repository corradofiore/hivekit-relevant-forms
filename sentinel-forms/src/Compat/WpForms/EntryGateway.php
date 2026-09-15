<?php
/**
 * wpforms()->entry->get() equivalent (docs/wpforms-compatibility.md).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Compat\WpForms;

use SentinelForms\Entries\Entry;
use SentinelForms\Entries\EntryRepository;
use SentinelForms\Forms\FormRepository;

/**
 * Loads Sentinel entries in the WPForms entry shape for consumers.
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
