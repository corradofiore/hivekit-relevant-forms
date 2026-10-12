/**
 * Relevant Forms confirmation cleanup.
 *
 * A successful submission redirects (PRG), so the page comes back with
 * `relevant_form_success=<id>` in the URL and the server answers with the
 * confirmation message instead of the form. Dropping the parameter once the
 * page is showing keeps the address bar clean and brings the form back on a
 * reload — the behaviour visitors get from an AJAX-confirmed form.
 */
( function () {
	'use strict';

	var params;

	try {
		params = new URLSearchParams( window.location.search );
	} catch ( error ) {
		return;
	}

	if ( ! params.has( 'relevant_form_success' ) ) {
		return;
	}

	params.delete( 'relevant_form_success' );

	var query = params.toString();

	window.history.replaceState(
		null,
		'',
		window.location.pathname + ( query ? '?' + query : '' ) + window.location.hash
	);
} )();
