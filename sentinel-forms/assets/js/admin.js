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
} )();

/**
 * Forms list: "Used on" popovers (view all pages a form is embedded in).
 */
( function () {
	'use strict';

	var TOGGLE = '.sentinel-forms-used-on__toggle';

	function closeAll() {
		document.querySelectorAll( TOGGLE ).forEach( function ( toggle ) {
			var popover = document.getElementById( toggle.getAttribute( 'aria-controls' ) );

			if ( popover && ! popover.hidden ) {
				popover.hidden = true;
				toggle.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var toggle = event.target.closest ? event.target.closest( TOGGLE ) : null;

		if ( ! toggle ) {
			if ( ! event.target.closest || ! event.target.closest( '.sentinel-forms-used-on__popover' ) ) {
				closeAll();
			}

			return;
		}

		var popover = document.getElementById( toggle.getAttribute( 'aria-controls' ) );

		if ( ! popover ) {
			return;
		}

		var willOpen = popover.hidden;

		closeAll();
		popover.hidden = ! willOpen;
		toggle.setAttribute( 'aria-expanded', willOpen ? 'true' : 'false' );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) {
			closeAll();
		}
	} );
} )();
