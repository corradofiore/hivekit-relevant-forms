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

	/**
	 * Bind every counter under a root.
	 *
	 * @param {Element} root Scope to search (document when omitted).
	 */
	function boot( root ) {
		( root || document ).querySelectorAll( '.relevant-forms-char-count[data-rf-count-for]' ).forEach( function ( counter ) {
			if ( '1' === counter.getAttribute( 'data-rf-counter-ready' ) ) {
				return;
			}

			var control = document.getElementById( counter.getAttribute( 'data-rf-count-for' ) );

			if ( ! control ) {
				return;
			}

			counter.setAttribute( 'data-rf-counter-ready', '1' );

			control.addEventListener( 'input', function () {
				update( counter, control );
			} );

			// Normalise the server-rendered count (and reveal or hide it).
			update( counter, control );
		} );
	}

	boot( document );

	// Allow re-runs on dynamically injected markup (Divi previews).
	( window.relevantFormsFrontend = window.relevantFormsFrontend || [] ).push( boot );
}() );
