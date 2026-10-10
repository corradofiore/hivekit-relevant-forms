/**
 * Relevant Forms real-time email check.
 *
 * While a visitor types an email address, the domain is checked against its MX
 * records (and, when the site asks for it, against the reachability of its mail
 * server). The verdict appears as an inline hint under the field.
 *
 * The check is advisory on the client and authoritative on the server: a
 * definitive "this domain cannot receive mail" also sets the field's custom
 * validity so the browser blocks the submission, exactly like the server
 * would. Anything inconclusive — no network, an expired nonce, a slow DNS
 * server — silently clears the hint and never blocks anyone.
 */
( function () {
	'use strict';

	var settings = window.relevantFormsEmailCheck || {};

	if ( ! settings.endpoint || ! settings.action || ! window.fetch ) {
		return;
	}

	var strings  = settings.strings || {};
	var DEBOUNCE = 600;

	/**
	 * Hint element of a control, created on first use.
	 *
	 * @param {HTMLInputElement} input Email control.
	 * @return {Element} Hint element.
	 */
	function hintFor( input ) {
		var wrapper = input.closest( '.relevant-forms-field' ) || input.parentNode;
		var hint    = wrapper.querySelector( '.relevant-forms-email-hint' );

		if ( hint ) {
			return hint;
		}

		hint = document.createElement( 'p' );
		hint.className = 'relevant-forms-email-hint';
		hint.setAttribute( 'role', 'status' );
		hint.setAttribute( 'aria-live', 'polite' );

		input.insertAdjacentElement( 'afterend', hint );

		return hint;
	}

	/**
	 * Show or clear the hint under a control.
	 *
	 * @param {HTMLInputElement} input   Email control.
	 * @param {string}           state   '', 'checking', 'warning' or 'error'.
	 * @param {string}           message Text to show.
	 */
	function setHint( input, state, message ) {
		var hint = hintFor( input );

		hint.className = 'relevant-forms-email-hint' + ( state ? ' relevant-forms-email-hint--' + state : '' );
		hint.textContent = message || '';
	}

	/**
	 * Reset everything the check may have set on a control.
	 *
	 * @param {HTMLInputElement} input Email control.
	 */
	function reset( input ) {
		input.setCustomValidity( '' );
		input.setAttribute( 'data-rf-email-checked', '' );
		setHint( input, '', '' );
	}

	/**
	 * Apply a verdict returned by the endpoint.
	 *
	 * @param {HTMLInputElement} input  Email control.
	 * @param {string}           value  Address the verdict is about.
	 * @param {Object}           result Payload of the response.
	 */
	function apply( input, value, result ) {
		if ( input.value.trim() !== value ) {
			return;
		}

		input.setAttribute( 'data-rf-email-checked', value );

		if ( 'ok' === result.status || 'unknown' === result.status ) {
			setHint( input, '', '' );
			input.setCustomValidity( '' );

			return;
		}

		if ( result.blocking ) {
			setHint( input, 'error', result.message );
			input.setCustomValidity( result.message );

			return;
		}

		// Inconclusive enough to mention, but the server accepts it.
		setHint( input, 'warning', result.message );
		input.setCustomValidity( '' );
	}

	/**
	 * Ask the endpoint about an address.
	 *
	 * @param {HTMLInputElement} input Email control.
	 */
	function check( input ) {
		var value = input.value.trim();

		if ( '' === value || input.getAttribute( 'data-rf-email-checked' ) === value ) {
			return;
		}

		// A value without a domain cannot say anything the browser has not
		// already said through its own validation.
		if ( ! /^[^@\s]+@[^@\s.]+(\.[^@\s.]+)+$/.test( value ) ) {
			reset( input );

			return;
		}

		inFlight += 1;
		inFlight += 1;
		setHint( input, 'checking', strings.checking || '' );

		var body = new window.FormData();

		body.append( 'action', settings.action );
		body.append( 'nonce', settings.nonce || '' );
		body.append( 'email', value );

		window
			.fetch( settings.endpoint, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json().catch( function () {
					return null;
				} );
			} )
			.then( function ( payload ) {
				if ( ! payload ) {
					reset( input );

					return;
				}

				if ( payload.success && payload.data ) {
					apply( input, value, payload.data );

					return;
				}

				// An error payload (invalid syntax, rate limited) is the
				// server's business, not a verdict: clear and stay quiet.
				reset( input );
			} )
			.catch( function () {
				reset( input );
			} );
	}

	/**
	 * Whether a form is a real submission form (the editor preview is not
	 * submitted, and the admin screen checks addresses itself).
	 *
	 * @param {Element} form Form element.
	 * @return {boolean} True when the check applies.
	 */
	function isLive( form ) {
		return ! form.hasAttribute( 'data-rf-form-preview' );
	}

	/**
	 * Attach to the email controls of one form.
	 *
	 * @param {Element} form Form element.
	 */
	function watch( form ) {
		var controls = form.querySelectorAll( 'input[type="email"][name^="relevant_field_"]' );

		Array.prototype.forEach.call( controls, function ( input ) {
			var timer = 0;

			input.addEventListener( 'blur', function () {
				check( input );
			} );

			input.addEventListener( 'input', function () {
				reset( input );

				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					check( input );
				}, DEBOUNCE );
			} );
		} );
	}

	/**
	 * Boot.
	 */
	function boot() {
		var forms = document.querySelectorAll( 'form.relevant-forms' );

		Array.prototype.forEach.call( forms, function ( form ) {
			if ( ! isLive( form ) ) {
				return;
			}

			watch( form );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
