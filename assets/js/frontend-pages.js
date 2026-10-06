/**
 * Relevant Forms front-end multi-page navigation.
 *
 * The server renders every field of a paginated form inside its own page
 * container (`[data-rf-page]`). This script turns that markup into a wizard:
 * one page is visible at a time, Back/Next buttons drive navigation and the
 * submit row only appears on the last reachable page.
 *
 * Without JavaScript every page stays visible and the form still submits in
 * one POST, so there is no separate no-JS code path to maintain.
 */
( function () {
	'use strict';

	/**
	 * Pages that currently contain at least one visible field.
	 *
	 * Conditional logic can hide every field of a page; such a page is
	 * skipped and the step counter shrinks with it.
	 *
	 * @param {Array} pages All page containers, in document order.
	 * @return {Array} Reachable pages.
	 */
	function activePages( pages ) {
		var active = pages.filter( function ( page ) {
			return Array.prototype.some.call(
				page.querySelectorAll( '.relevant-forms-field' ),
				function ( field ) {
					return ! field.hidden;
				}
			);
		} );

		return active.length ? active : pages.slice( 0, 1 );
	}

	/**
	 * Run native constraint validation for one page.
	 *
	 * @param {Element} page Page container to validate.
	 * @return {boolean} Whether every visible control of the page is valid.
	 */
	function validatePage( page ) {
		var controls = page.querySelectorAll( 'input, select, textarea' );

		for ( var i = 0; i < controls.length; i++ ) {
			var control = controls[ i ];

			if ( control.disabled || control.closest( '[hidden]' ) ) {
				continue;
			}

			if ( ! control.checkValidity() ) {
				control.reportValidity();

				return false;
			}
		}

		return true;
	}

	/**
	 * Fill the "Step %1$d of %2$d" template with translated labels.
	 *
	 * @param {string} template Step template from the server.
	 * @param {number} current  One-based current step.
	 * @param {number} total    Total reachable steps.
	 * @return {string} Human readable step indicator.
	 */
	function formatStep( template, current, total ) {
		return template.replace( '%1$d', String( current ) ).replace( '%2$d', String( total ) );
	}

	/**
	 * Turn one paginated form into a wizard.
	 *
	 * @param {Element} form Form element carrying data-rf-page-* attributes.
	 */
	function init( form ) {
		if ( '1' === form.getAttribute( 'data-rf-pages-ready' ) ) {
			return;
		}

		var pages = Array.prototype.slice.call( form.querySelectorAll( '[data-rf-page]' ) );

		if ( pages.length < 2 ) {
			return;
		}

		form.setAttribute( 'data-rf-pages-ready', '1' );

		var prevLabel    = form.getAttribute( 'data-rf-page-prev' ) || 'Previous';
		var nextLabel    = form.getAttribute( 'data-rf-page-next' ) || 'Next';
		var stepTemplate = form.getAttribute( 'data-rf-page-step' ) || 'Step %1$d of %2$d';
		var submitRow    = form.querySelector( '[data-rf-submit-row]' );
		var current      = 0;

		pages.forEach( function ( page, index ) {
			var nav = document.createElement( 'div' );
			nav.className = 'relevant-forms-page-nav';

			if ( index > 0 ) {
				var back = document.createElement( 'button' );
				back.type = 'button';
				back.className = 'relevant-forms-page-back';
				back.textContent = prevLabel;
				back.addEventListener( 'click', function () {
					show( current - 1, true );
				} );
				nav.appendChild( back );
			}

			if ( index < pages.length - 1 ) {
				var next = document.createElement( 'button' );
				next.type = 'button';
				next.className = 'relevant-forms-page-next';
				next.textContent = nextLabel;
				next.addEventListener( 'click', function () {
					if ( validatePage( activePages( pages )[ current ] ) ) {
						show( current + 1, true );
					}
				} );
				nav.appendChild( next );
			}

			if ( nav.childNodes.length ) {
				page.appendChild( nav );
			}
		} );

		/**
		 * Show one reachable page.
		 *
		 * @param {number}  index One-based target page index in the active list.
		 * @param {boolean} focus Whether to move focus into the new page.
		 */
		function show( index, focus ) {
			var active = activePages( pages );

			current = Math.max( 0, Math.min( index, active.length - 1 ) );

			pages.forEach( function ( page ) {
				page.hidden = true;
			} );

			var target = active[ current ];
			target.hidden = false;

			var indicator = target.querySelector( '[data-rf-page-indicator]' );

			if ( indicator ) {
				indicator.textContent = formatStep( stepTemplate, current + 1, active.length );
			}

			if ( submitRow ) {
				submitRow.hidden = current !== active.length - 1;
			}

			if ( ! focus ) {
				return;
			}

			var focusable = target.querySelector( 'input, select, textarea, button' );

			if ( focusable ) {
				focusable.focus( { preventScroll: true } );
			}

			target.scrollIntoView( { block: 'nearest' } );
		}

		// Keep keyboard flow inside the current page: Enter advances a step
		// instead of submitting a form the visitor has not completed yet.
		form.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' !== event.key || 'TEXTAREA' === event.target.tagName ) {
				return;
			}

			var active = activePages( pages );
			var page   = event.target.closest ? event.target.closest( '[data-rf-page]' ) : null;

			if ( ! page || page !== active[ current ] || current === active.length - 1 ) {
				return;
			}

			var next = page.querySelector( '.relevant-forms-page-next' );

			if ( next ) {
				event.preventDefault();
				next.click();
			}
		} );

		// Conditional fields can remove every field from a page: re-evaluate
		// which pages are reachable whenever visibility may have changed.
		function refresh() {
			var active = activePages( pages );

			if ( current > active.length - 1 ) {
				show( active.length - 1, false );

				return;
			}

			show( current, false );
		}

		form.addEventListener( 'relevant-forms:visibility', refresh );
		form.addEventListener( 'change', refresh );

		// After a failed submission round-trip, reopen the page that holds the
		// first validation error.
		var start = 0;
		var error = form.querySelector( '.relevant-forms-error' );

		if ( error ) {
			var erroredPage = error.closest ? error.closest( '[data-rf-page]' ) : null;

			if ( erroredPage ) {
				start = Math.max( 0, activePages( pages ).indexOf( erroredPage ) );
			}
		}

		show( start, false );
	}

	/**
	 * Bind every multi-page form under a root.
	 *
	 * @param {Element} root Scope to search (document when omitted).
	 */
	function boot( root ) {
		( root || document ).querySelectorAll( '.relevant-forms[data-rf-page-step]' ).forEach( function ( form ) {
				// init() owns the ready flag: it only marks a form as
				// initialised once it has actually taken it over.			init( form );
		} );	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			boot( document );
		} );
	} else {
		boot( document );
	}

	// Allow re-runs on dynamically injected markup (Divi previews).
	( window.relevantFormsFrontend = window.relevantFormsFrontend || [] ).push( boot );
}() );
