/**
 * Relevant Forms front-end live payment total.
 *
 * Sums the server-rendered prices (minor units in data-rf-price) of the
 * selected options plus any visitor-entered amount, and updates every
 * [data-rf-payment-total] display on the form. The server recomputes the
 * authoritative total at submit, so this script only shortens the feedback
 * loop — it never sends a price anywhere.
 */
( function () {
	'use strict';

	/**
	 * Parse a visitor-entered decimal amount ("10", "10.50", "10,50")
	 * into minor units; malformed input contributes nothing.
	 *
	 * @param {string} value    Raw input value.
	 * @param {number} decimals Currency decimal places.
	 * @return {number} Amount in minor units.
	 */
	function parseAmount( value, decimals ) {
		var normalised = String( value ).replace( /\s+/g, '' ).replace( ',', '.' );

		if ( ! /^\d+(?:\.\d+)?$/.test( normalised ) ) {
			return 0;
		}

		var parts = normalised.split( '.' );
		var whole = parts[ 0 ];
		var frac = ( parts[ 1 ] || '' );

		// Round half-up to the currency's precision with integer math.
		var padded = ( frac + '000000000000' ).slice( 0, decimals + 1 );
		var units = parseInt( whole + padded.slice( 0, decimals ), 10 ) || 0;

		return units + ( Number( padded.charAt( decimals ) ) >= 5 ? 1 : 0 );
	}

	/**
	 * Format minor units for display, e.g. "$1,250.00".
	 *
	 * @param {number} minor    Amount in minor units.
	 * @param {number} decimals Currency decimal places.
	 * @param {string} symbol   Currency symbol ('' when none).
	 * @return {string} Display string.
	 */
	function format( minor, decimals, symbol ) {
		var value = String( Math.max( 0, minor ) );

		if ( decimals > 0 ) {
			value = value.padStart( decimals + 1, '0' );

			var whole = value.slice( 0, -decimals ).replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
			var frac = value.slice( -decimals );

			value = whole + '.' + frac;
		} else {
			value = value.replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
		}

		return symbol + value;
	}

	/**
	 * Whether the control is currently hidden by a visibility rule.
	 *
	 * @param {Element} element Control or price marker.
	 * @return {boolean} True when hidden.
	 */
	function isHidden( element ) {
		return null !== element.closest( '[hidden]' );
	}

	/**
	 * Recompute one form's total.
	 *
	 * @param {HTMLFormElement} form            The form.
	 * @param {Element}         totalElement    Element carrying the display config.
	 */
	function update( form, totalElement ) {
		var decimals = parseInt( totalElement.getAttribute( 'data-rf-decimals' ) || '2', 10 );
		var symbol = totalElement.getAttribute( 'data-rf-symbol' ) || '';
		var total = 0;

		form.querySelectorAll( '[data-rf-price]' ).forEach( function ( element ) {
			if ( isHidden( element ) ) {
				return;
			}

			var tag = element.tagName;

			if ( 'OPTION' === tag && ! element.selected ) {
				return;
			}

			if ( ( 'INPUT' === tag ) && ( 'checkbox' === element.type || 'radio' === element.type ) && ! element.checked ) {
				return;
			}

			total += parseInt( element.getAttribute( 'data-rf-price' ), 10 ) || 0;
		} );

		form.querySelectorAll( '[data-rf-amount]' ).forEach( function ( input ) {
			if ( isHidden( input ) ) {
				return;
			}

			total += parseAmount( input.value, parseInt( input.getAttribute( 'data-rf-decimals' ) || String( decimals ), 10 ) );
		} );

		totalElement.textContent = format( total, decimals, symbol );
	}

	document.querySelectorAll( 'form.relevant-forms' ).forEach( function ( form ) {
		var totals = form.querySelectorAll( '[data-rf-payment-total]' );

		if ( 0 === totals.length ) {
			return;
		}

		var refresh = function () {
			totals.forEach( function ( totalElement ) {
				update( form, totalElement );
			} );
		};

		form.addEventListener( 'input', refresh );
		form.addEventListener( 'change', refresh );
		refresh();
	} );
}() );
