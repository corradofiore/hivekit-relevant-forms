/**
 * Relevant Forms front-end character counter for limited paragraph fields.
 *
 * The server renders the counter ("0/500", hidden when the field has no
 * limit); this script keeps it in sync while the visitor types.
 */
( function () {
	'use strict';

	/**
	 * Refresh one counter from its control.
	 *
	 * @param {Element} counter Counter paragraph.
	 * @param {Element} control Textarea being counted.
	 */
	function update( counter, control ) {
		var limit = control.maxLength;
		var used = control.value.length;

		if ( limit > 0 ) {
			counter.hidden = false;
			counter.textContent = used + '/' + limit;
		} else {
			counter.hidden = true;
			counter.textContent = String( used );
		}
	}

	document.querySelectorAll( '.relevant-forms-char-count[data-rf-count-for]' ).forEach( function ( counter ) {
		var control = document.getElementById( counter.getAttribute( 'data-rf-count-for' ) );

		if ( ! control ) {
			return;
		}

		control.addEventListener( 'input', function () {
			update( counter, control );
		} );

		// Normalise the server-rendered count (and reveal or hide it).
		update( counter, control );
	} );
}() );
