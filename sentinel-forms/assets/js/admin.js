/**
 * Sentinel Forms admin builder interactions (no dependencies).
 */
( function () {
	'use strict';

	/**
	 * Generate a short unique field id.
	 *
	 * @return {string} Field id.
	 */
	function generateId() {
		return 'f_' + Math.random().toString( 36 ).slice( 2, 9 );
	}

	/**
	 * Add a new field row from the type template.
	 *
	 * @param {string} type Field type.
	 */
	function addField( type ) {
		var template = document.getElementById( 'sf-field-template-' + type );
		var container = document.getElementById( 'sf-fields' );

		if ( ! template || ! container ) {
			return;
		}

		var id = generateId();
		var wrapper = document.createElement( 'div' );
		wrapper.innerHTML = template.innerHTML.replace( /\{ID\}/g, id );

		while ( wrapper.firstChild ) {
			container.appendChild( wrapper.firstChild );
		}

		container.lastElementChild.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	/**
	 * Move a field row one position in the requested direction.
	 *
	 * @param {Element} row  Field row element.
	 * @param {number}  step -1 (up) or +1 (down).
	 */
	function move( row, step ) {
		var sibling = step < 0 ? row.previousElementSibling : row.nextElementSibling;

		if ( ! sibling || ! sibling.classList.contains( 'sf-field' ) ) {
			return;
		}

		row.parentNode.insertBefore( step < 0 ? row : sibling.nextSibling, step < 0 ? sibling : row );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( 'button' );
		var row;

		if ( ! target ) {
			return;
		}

		row = target.closest( '.sf-field' );

		if ( ! row ) {
			return;
		}

		if ( target.classList.contains( 'sf-remove' ) ) {
			row.remove();
		} else if ( target.classList.contains( 'sf-move-up' ) ) {
			move( row, -1 );
		} else if ( target.classList.contains( 'sf-move-down' ) ) {
			move( row, 1 );
		}
	} );

	var addSelect = document.getElementById( 'sf-add-field' );

	if ( addSelect ) {
		addSelect.addEventListener( 'change', function () {
			if ( this.value ) {
				addField( this.value );
				this.value = '';
			}
		} );
	}

	/*
	 * Redirect picker: a selected page or post anchors the redirect by ID —
	 * the permalink is resolved on submission, so slug changes keep working.
	 */
	var redirectPicker = document.getElementById( 'sf-redirect-page' );

	/*
	 * Large sites: the picker ships with only the current selection and is
	 * filled by an AJAX search, so the drop-down shows just the matches for
	 * the search key.
	 */
	var redirectSearch = document.getElementById( 'sf-redirect-search' );

	if ( redirectPicker && redirectSearch && window.sentinelFormsAdmin ) {
		( function () {
			var settings = window.sentinelFormsAdmin;
			var placeholder = redirectPicker.options[ 0 ];
			var timer = null;
			var sequence = 0;

			/**
			 * The currently selected item, if any.
			 *
			 * @return {Object|null} Selection as {value, label}.
			 */
			function selection() {
				if ( redirectPicker.value === '' ) {
					return null;
				}

				return {
					value: redirectPicker.value,
					label: redirectPicker.options[ redirectPicker.selectedIndex ].textContent
				};
			}

			/**
			 * Rebuild the drop-down: placeholder + current selection + matches.
			 *
			 * @param {Array}  matches Search results as [{id, title}].
			 * @param {string} query   Search term the matches belong to.
			 */
			function render( matches, query ) {
				var selected = selection();
				var fragment = document.createDocumentFragment();

				while ( redirectPicker.firstChild ) {
					redirectPicker.removeChild( redirectPicker.firstChild );
				}

				fragment.appendChild( placeholder );

				if ( selected ) {
					// Keep the selection visible (and submitted) while filtering.
					var kept = document.createElement( 'option' );
					kept.value = selected.value;
					kept.textContent = selected.label;
					fragment.appendChild( kept );
				}

				matches.forEach( function ( match ) {
					if ( selected && String( match.id ) === selected.value ) {
						return;
					}

					var option = document.createElement( 'option' );
					option.value = String( match.id );
					option.textContent = match.title;
					fragment.appendChild( option );
				} );

				if ( query !== '' && matches.length === 0 ) {
					var none = document.createElement( 'option' );
					none.disabled = true;
					none.textContent = settings.noResults;
					fragment.appendChild( none );
				}

				redirectPicker.appendChild( fragment );
				redirectPicker.value = selected ? selected.value : '';
			}

			/**
			 * Fetch matches for a search term.
			 *
			 * @param {string} query Search term.
			 */
			function search( query ) {
				var token = ++sequence;
				var url = settings.ajaxUrl
					+ '?action=sentinel_forms_search_redirect_targets'
					+ '&_wpnonce=' + encodeURIComponent( settings.searchNonce )
					+ '&search=' + encodeURIComponent( query );

				window.fetch( url )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( body ) {
						if ( token !== sequence ) {
							return;
						}

						render( body && body.data && body.data.results ? body.data.results : [], query );
					} )
					.catch( function () {} );
			}

			redirectSearch.addEventListener( 'input', function () {
				var query = this.value.trim();

				if ( timer ) {
					window.clearTimeout( timer );
				}

				if ( query === '' ) {
					// Clearing the search clears the anchor selection.
					redirectPicker.value = '';
					render( [], '' );
					return;
				}

				if ( query.length < 2 ) {
					return;
				}

				timer = window.setTimeout(
					function () {
						search( query );
					},
					250
				);
			} );

			redirectPicker.addEventListener( 'change', function () {
				var selected = selection();

				redirectSearch.value = selected ? selected.label : '';
				render( [], '' );
			} );
		} )();
	}

	/*
	 * Confirmation modes: only the row for the selected mechanism is shown.
	 * Every row stays in the DOM, so saving always carries all three values.
	 */
	var confirmationModes = document.querySelectorAll( 'input[name="confirmation_mode"]' );

	if ( confirmationModes.length ) {
		var confirmationRows = {
			message: document.getElementById( 'sf-confirmation-message-row' ),
			url: document.getElementById( 'sf-confirmation-url-row' ),
			page: document.getElementById( 'sf-confirmation-page-row' )
		};

		/**
		 * Show the row that matches the checked confirmation mode.
		 */
		function syncConfirmationRows() {
			Object.keys( confirmationRows ).forEach( function ( mode ) {
				var radio = document.querySelector( 'input[name="confirmation_mode"][value="' + mode + '"]' );

				if ( confirmationRows[ mode ] ) {
					confirmationRows[ mode ].hidden = ! ( radio && radio.checked );
				}
			} );
		}

		Array.prototype.forEach.call( confirmationModes, function ( radio ) {
			radio.addEventListener( 'change', syncConfirmationRows );
		} );

		syncConfirmationRows();
	}

	/**
	 * Toggle the form editor tabs (settings / fields).
	 *
	 * Both panels stay in the DOM so no input is ever dropped from a save
	 * request, and the current tab is reflected in the URL hash so reloading
	 * the editor keeps the section the user was on.
	 */
	function initTabs() {
		var tabs = Array.prototype.slice.call( document.querySelectorAll( '.sentinel-forms-tabs .nav-tab' ) );
		var items = [];

		if ( ! tabs.length ) {
			return;
		}

		tabs.forEach( function ( tab ) {
			var panel = document.getElementById( tab.getAttribute( 'href' ).slice( 1 ) );

			if ( panel ) {
				items.push( { tab: tab, panel: panel } );
			}
		} );

		/**
		 * Show the panel for the given hash and mark its tab active.
		 *
		 * @param {string} hash Panel hash, e.g. "#sf-panel-fields".
		 */
		function activate( hash ) {
			items.forEach( function ( item ) {
				var isActive = item.tab.getAttribute( 'href' ) === hash;

				item.tab.classList.toggle( 'nav-tab-active', isActive );
				item.panel.hidden = ! isActive;

				if ( isActive ) {
					item.tab.setAttribute( 'aria-current', 'true' );
				} else {
					item.tab.removeAttribute( 'aria-current' );
				}
			} );
		}

		items.forEach( function ( item ) {
			item.tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				activate( item.tab.getAttribute( 'href' ) );

				if ( window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', item.tab.getAttribute( 'href' ) );
				}
			} );
		} );

		var initial = window.location.hash;
		var known = items.some( function ( item ) {
			return item.tab.getAttribute( 'href' ) === initial;
		} );

		activate( known ? initial : items[ 0 ].tab.getAttribute( 'href' ) );
	}

	initTabs();
} )();
