/**
 * Relevant Forms front-end entry preview.
 *
 * Keeps the "review your answers" block in sync with the visitor's input.
 * The server renders one row per candidate field (hidden when empty); this
 * script toggles rows and updates their value text as the visitor types,
 * including values living on other pages of a multi-page form, conditional
 * visibility changes and the live payment total.
 *
 * Values the browser cannot read back (an already chosen file) are left as
 * rendered, so refreshing never blanks a value that is still there.
 */
( function () {
	'use strict';

	/**
	 * Visible label text of one choice control.
	 *
	 * @param {Element} control Radio or checkbox input.
	 * @return {string} Label text, or the raw value when no label is found.
	 */
	function labelFor( control ) {
		var label = control.id ? document.querySelector( 'label[for="' + control.id + '"]' ) : null;

		return label ? label.textContent.trim() : '';
	}

	/**
	 * Current value of one preview row.
	 *
	 * @param {Element} form Form element.
	 * @param {Element} row  Preview row element.
	 * @return {string|null} Display text, or null to keep the rendered value.
	 */
	function controlValue( form, row ) {
		var fieldId  = row.getAttribute( 'data-rf-preview-row' );
		var controls = form.querySelectorAll(
			'[name="relevant_field_' + fieldId + '"], [name="relevant_field_' + fieldId + '[]"]'
		);

		// The live payment total has no control of its own.
		if ( ! controls.length ) {
			var total = form.querySelector( '[data-rf-payment-total]' );

			return total ? total.textContent.trim() : null;
		}

		var first   = controls[ 0 ];
		var wrapper = first.closest( '.relevant-forms-field' );

		// Conditionally hidden fields are left out of the preview.
		if ( wrapper && wrapper.hidden ) {
			return '';
		}

		var tag  = first.tagName;
		var type = ( first.getAttribute( 'type' ) || 'text' ).toLowerCase();
		var i;

		if ( 'SELECT' === tag ) {
			var option = first.options[ first.selectedIndex ];

			return option && '' !== option.value ? option.textContent.trim() : '';
		}

		if ( 'INPUT' === tag && 'file' === type ) {
			if ( ! first.files || ! first.files.length ) {
				return null;
			}

			var names = [];

			for ( i = 0; i < first.files.length; i++ ) {
				names.push( first.files[ i ].name );
			}

			return names.join( ', ' );
		}

		if ( 'INPUT' === tag && 'checkbox' === type ) {
			if ( first.closest( '.relevant-forms-field--consent' ) ) {
				var box = row.closest( '[data-rf-preview]' );

				return first.checked ? ( box && box.getAttribute( 'data-rf-preview-yes' ) ) || 'Yes' : '';
			}

			var labels = [];

			Array.prototype.forEach.call( controls, function ( control ) {
				if ( control.checked ) {
					labels.push( labelFor( control ) || control.value );
				}
			} );

			return labels.join( ', ' );
		}

		if ( 'INPUT' === tag && 'radio' === type ) {
			var checked = null;

			Array.prototype.forEach.call( controls, function ( control ) {
				if ( control.checked ) {
					checked = control;
				}
			} );

			return checked ? labelFor( checked ) || checked.value : '';
		}

		return String( first.value || '' ).trim();
	}

	/**
	 * Recompute every preview block of one form.
	 *
	 * @param {Element} form Form element.
	 */
	function refresh( form ) {
		Array.prototype.forEach.call( form.querySelectorAll( '[data-rf-preview]' ), function ( box ) {
			var rows    = box.querySelectorAll( '[data-rf-preview-row]' );
			var visible = 0;

			Array.prototype.forEach.call( rows, function ( row ) {
				var value = controlValue( form, row );

				if ( null !== value ) {
					row.querySelector( '.relevant-forms-preview-value' ).textContent = value;
					row.hidden = '' === value;
				}

				if ( ! row.hidden ) {
					visible++;
				}
			} );

			var empty = box.querySelector( '[data-rf-preview-empty]' );

			if ( empty ) {
				empty.hidden = visible > 0;
			}
		} );
	}

	function boot() {
		document.querySelectorAll( 'form.relevant-forms' ).forEach( function ( form ) {
			if ( ! form.querySelector( '[data-rf-preview]' ) || '1' === form.getAttribute( 'data-rf-preview-ready' ) ) {
				return;
			}

			form.setAttribute( 'data-rf-preview-ready', '1' );

			// Defer to the next tick so handlers updating the live total have
			// already run when the total row is read.
			var pending = false;

			function queue() {
				if ( pending ) {
					return;
				}

				pending = true;
				setTimeout( function () {
					pending = false;
					refresh( form );
				}, 0 );
			}

			form.addEventListener( 'input', queue );
			form.addEventListener( 'change', queue );
			form.addEventListener( 'relevant-forms:visibility', queue );

			refresh( form );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
