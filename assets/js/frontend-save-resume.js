/**
 * Relevant Forms front-end Save and Resume.
 *
 * Two layers (docs/hooks.md):
 *  - browser-local drafts: debounced autosave to localStorage while the
 *    visitor fills the form, restored automatically on the next visit;
 *  - the Save and Resume button: asks for an email address, stores a
 *    partial entry on the server and emails a resume link.
 *
 * Without JavaScript the whole feature is inert: the button row stays
 * hidden and nothing is stored locally or remotely.
 */
( function () {
	'use strict';

	var settings = window.relevantFormsSaveResume || {};
	var strings  = settings.strings || {};

	/**
	 * localStorage when usable (private modes can throw), null otherwise.
	 *
	 * @return {Object|null} Storage or null.
	 */
	function storage() {
		try {
			var store = window.localStorage;

			store.setItem( '__rf_probe', '1' );
			store.removeItem( '__rf_probe' );

			return store;
		} catch ( error ) {
			return null;
		}
	}

	/**
	 * Field controls of a form, file inputs excluded.
	 *
	 * @param {Element} form Form element.
	 * @return {Array} Controls.
	 */
	function controls( form ) {
		return Array.prototype.filter.call(
			form.querySelectorAll( '[name^="relevant_field_"]' ),
			function ( control ) {
				return 'file' !== control.type;
			}
		);
	}

	/**
	 * Current wizard page (1 for single-page forms).
	 *
	 * @param {Element} form Form element.
	 * @return {number} One-based page.
	 */
	function currentPage( form ) {
		var pages = form.querySelectorAll( '[data-rf-page]' );

		for ( var i = 0; i < pages.length; i++ ) {
			if ( ! pages[ i ].hidden ) {
				return i + 1;
			}
		}

		return 1;
	}

	/**
	 * Read control values into a plain object keyed by input name.
	 *
	 * @param {Element} form Form element.
	 * @return {Object} Values.
	 */
	function readValues( form ) {
		var values = {};

		controls( form ).forEach( function ( control ) {
			if ( 'checkbox' === control.type ) {
				if ( ! control.checked ) {
					return;
				}

				if ( '[]' === control.name.slice( -2 ) ) {
					values[ control.name ] = values[ control.name ] || [];
					values[ control.name ].push( control.value );

					return;
				}

				values[ control.name ] = control.value;

				return;
			}

			if ( 'radio' === control.type && ! control.checked ) {
				return;
			}

			values[ control.name ] = control.value;
		} );

		return values;
	}

	/**
	 * Apply stored values onto the controls and wake the other front-end
	 * scripts (conditional visibility, totals, counters).
	 *
	 * @param {Element} form   Form element.
	 * @param {Object}  values Values keyed by input name.
	 */
	function writeValues( form, values ) {
		controls( form ).forEach( function ( control ) {
			if ( ! Object.prototype.hasOwnProperty.call( values, control.name ) ) {
				return;
			}

			var stored = values[ control.name ];

			if ( 'checkbox' === control.type ) {
				control.checked = Array.isArray( stored )
					? stored.indexOf( control.value ) !== -1
					: String( stored ) === control.value;
			} else if ( 'radio' === control.type ) {
				control.checked = String( stored ) === control.value;
			} else {
				control.value = Array.isArray( stored ) ? stored.join( ', ' ) : String( stored );
			}

			control.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			control.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	}

	/**
	 * Clear every field control.
	 *
	 * @param {Element} form Form element.
	 */
	function clearValues( form ) {
		controls( form ).forEach( function ( control ) {
			if ( 'checkbox' === control.type || 'radio' === control.type ) {
				control.checked = false;
			} else {
				control.value = '';
			}

			control.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	}

	/**
	 * Draft storage key for a form.
	 *
	 * @param {Element} form Form element.
	 * @return {string} Key.
	 */
	function draftKey( form ) {
		return 'relevant-forms:draft:' + ( form.getAttribute( 'data-form-id' ) || '0' );
	}

	/**
	 * Read the stored draft (null when missing, malformed or expired).
	 *
	 * @param {Element} form Form element.
	 * @return {Object|null} Draft payload.
	 */
	function readDraft( form ) {
		var store = storage();

		if ( ! store ) {
			return null;
		}

		var expiryDays = parseInt( form.getAttribute( 'data-rf-resume-expiry' ) || '30', 10 );

		try {
			var draft = JSON.parse( store.getItem( draftKey( form ) ) || 'null' );

			if ( ! draft || 1 !== draft.v || ! draft.savedAt || ! draft.values ) {
				return null;
			}

			if ( expiryDays > 0 && Date.now() - draft.savedAt > expiryDays * 86400000 ) {
				store.removeItem( draftKey( form ) );

				return null;
			}

			return draft;
		} catch ( error ) {
			return null;
		}
	}

	/**
	 * Discard the stored draft.
	 *
	 * @param {Element} form Form element.
	 */
	function clearDraft( form ) {
		var store = storage();

		if ( store ) {
			try {
				store.removeItem( draftKey( form ) );
			} catch ( error ) {
				// Nothing stored, nothing to clear.
			}
		}
	}

	/**
	 * Show the transient "Draft saved" indicator.
	 *
	 * @param {Element} form Form element.
	 */
	function showDraftIndicator( form ) {
		var indicator = form.querySelector( '[data-rf-draft-indicator]' );

		if ( ! indicator ) {
			return;
		}

		indicator.textContent = ( strings.draftSaved || 'Draft saved in this browser' ) + ' · ' + new Date().toLocaleTimeString();
		indicator.hidden = false;

		window.clearTimeout( indicator.rfTimer );
		indicator.rfTimer = window.setTimeout( function () {
			indicator.hidden = true;
		}, 4000 );
	}

	/**
	 * Build the "restored draft" notice with its discard action.
	 *
	 * @param {Element} form  Form element.
	 * @param {Object}  draft Draft payload.
	 */
	function showRestoreNotice( form, draft ) {
		var row = form.querySelector( '[data-rf-save-resume-row]' );

		if ( ! row || ! draft.savedAt ) {
			return;
		}

		var notice = document.createElement( 'div' );
		notice.className = 'relevant-forms-notice relevant-forms-notice--info';
		notice.setAttribute( 'data-rf-draft-notice', '' );
		notice.setAttribute( 'role', 'status' );

		var when = new Date( draft.savedAt ).toLocaleString();

		notice.appendChild( document.createTextNode( ( strings.draftRestored || 'We restored your saved draft (%s).' ).replace( '%s', when ) + ' ' ) );

		var discard = document.createElement( 'button' );
		discard.type = 'button';
		discard.className = 'relevant-forms-notice__action';
		discard.textContent = strings.startFresh || 'Start fresh';
		discard.addEventListener( 'click', function () {
			clearValues( form );
			clearDraft( form );
			notice.remove();
		} );

		notice.appendChild( discard );
		row.parentNode.insertBefore( notice, row );
	}

	/**
	 * Restore a browser draft before the wizard boots.
	 *
	 * @param {Element} form Form element.
	 * @return {boolean} Whether a draft was restored.
	 */
	function restoreDraft( form ) {
		if ( '1' !== form.getAttribute( 'data-rf-drafts' ) ) {
			return false;
		}

		// A server resume link is an explicit, newer snapshot: it wins.
		if ( form.getAttribute( 'data-rf-resume-token' ) ) {
			return false;
		}

		var draft = readDraft( form );

		if ( ! draft ) {
			return false;
		}

		writeValues( form, draft.values );

		if ( draft.page > 1 && form.getAttribute( 'data-rf-page-step' ) ) {
			form.setAttribute( 'data-rf-initial-page', String( draft.page ) );
		}

		showRestoreNotice( form, draft );

		return true;
	}

	/**
	 * Bind the debounced autosave, flushes and submit cleanup.
	 *
	 * @param {Element} form Form element.
	 */
	function bindDrafts( form ) {
		var store = storage();

		if ( ! store || '1' !== form.getAttribute( 'data-rf-drafts' ) ) {
			return;
		}

		var timer = null;

		function saveNow() {
			timer = null;

			var key     = draftKey( form );
			var payload = {
				v: 1,
				savedAt: Date.now(),
				page: currentPage( form ),
				values: readValues( form )
			};

			try {
				var existing = JSON.parse( store.getItem( key ) || 'null' );

				if ( existing && existing.lastEmail ) {
					payload.lastEmail = existing.lastEmail;
				}

				store.setItem( key, JSON.stringify( payload ) );
				showDraftIndicator( form );
			} catch ( error ) {
				// Quota or serialisation problems: stay silent and keep working.
			}
		}

		function schedule() {
			if ( timer ) {
				window.clearTimeout( timer );
			}

			timer = window.setTimeout( saveNow, 600 );
		}

		form.addEventListener( 'input', schedule );
		form.addEventListener( 'change', schedule );
		form.addEventListener( 'click', function ( event ) {
			if ( event.target.closest && event.target.closest( '[data-rf-page-next], [data-rf-page-prev]' ) ) {
				window.setTimeout( saveNow, 0 );
			}
		} );
		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				saveNow();
			}
		} );
		window.addEventListener( 'pagehide', saveNow );
		form.addEventListener( 'submit', function () {
			clearDraft( form );
		} );

		form.rfSaveDraft = saveNow;
	}

	/**
	 * Build the FormData payload for a server-side save.
	 *
	 * @param {Element} form  Form element.
	 * @param {string}  email Requested address.
	 * @return {FormData} Payload.
	 */
	function savePayload( form, email ) {
		var data = new FormData();

		data.append( 'action', settings.action || 'relevant_forms_save_resume' );
		data.append( '_relevant_form_id', form.getAttribute( 'data-form-id' ) || '' );
		data.append( '_relevant_resume_nonce', form.getAttribute( 'data-rf-save-resume-nonce' ) || '' );
		data.append( 'save_resume_email', email );
		data.append( 'save_resume_page', String( currentPage( form ) ) );
		data.append( 'save_resume_token', form.getAttribute( 'data-rf-resume-token' ) || '' );
		data.append( 'save_resume_source_url', window.location.href.split( '#' )[ 0 ] );

		controls( form ).forEach( function ( control ) {
			if ( ( 'checkbox' === control.type || 'radio' === control.type ) && ! control.checked ) {
				return;
			}

			data.append( control.name, control.value );
		} );

		return data;
	}

	/**
	 * Open the email prompt and save the progress on the server.
	 *
	 * @param {Element} form Form element.
	 */
	function openModal( form ) {
		if ( form.rfModal ) {
			form.rfModal.querySelector( 'input[type="email"]' ).focus();

			return;
		}

		var draft = readDraft( form );

		var overlay = document.createElement( 'div' );
		overlay.className = 'relevant-forms-modal';

		var panel = document.createElement( 'div' );
		panel.className = 'relevant-forms-modal__panel';
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-modal', 'true' );

		var title = document.createElement( 'h3' );
		title.className = 'relevant-forms-modal__title';
		title.textContent = strings.title || 'Save and Resume';

		var intro = document.createElement( 'p' );
		intro.className = 'relevant-forms-modal__intro';
		intro.textContent = strings.intro || 'Enter your email address and we will send you a link to pick up where you left off.';

		var label = document.createElement( 'label' );
		label.className = 'relevant-forms-modal__label';
		label.textContent = strings.emailLabel || 'Email address';

		var input = document.createElement( 'input' );
		input.type = 'email';
		input.className = 'relevant-forms-modal__input';
		input.required = true;
		input.value = draft && draft.lastEmail ? draft.lastEmail : '';
		label.appendChild( input );

		var message = document.createElement( 'p' );
		message.className = 'relevant-forms-modal__message';
		message.setAttribute( 'role', 'status' );
		message.setAttribute( 'aria-live', 'polite' );
		message.hidden = true;

		var actions = document.createElement( 'div' );
		actions.className = 'relevant-forms-modal__actions';

		var submit = document.createElement( 'button' );
		submit.type = 'button';
		submit.className = 'relevant-forms-modal__submit wp-element-button';
		submit.textContent = strings.save || 'Email me the link';

		var cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'relevant-forms-modal__cancel';
		cancel.textContent = strings.cancel || 'Cancel';

		actions.appendChild( submit );
		actions.appendChild( cancel );

		panel.appendChild( title );
		panel.appendChild( intro );
		panel.appendChild( label );
		panel.appendChild( message );
		panel.appendChild( actions );
		overlay.appendChild( panel );
		document.body.appendChild( overlay );

		function close() {
			overlay.remove();
			form.rfModal = null;
		}

		cancel.addEventListener( 'click', close );
		overlay.addEventListener( 'click', function ( event ) {
			if ( event.target === overlay ) {
				close();
			}
		} );
		overlay.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				close();
			}
		} );

		input.focus();

		submit.addEventListener( 'click', function () {
			var email = input.value.trim();

			if ( ! email || email.indexOf( '@' ) === -1 ) {
				message.textContent = strings.invalidEmail || 'Enter a valid email address.';
				message.hidden = false;

				return;
			}

			submit.disabled = true;
			message.hidden = false;
			message.textContent = strings.saving || 'Saving…';

			window.fetch( settings.endpoint || '', {
				method: 'POST',
				credentials: 'same-origin',
				body: savePayload( form, email )
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( body ) {
				submit.disabled = false;

				if ( body && body.success && body.data ) {
					message.textContent = body.data.message || '';

					if ( body.data.token ) {
						form.setAttribute( 'data-rf-resume-token', body.data.token );
					}

					var key   = draftKey( form );
					var store = storage();

					if ( store ) {
						try {
							var stored = JSON.parse( store.getItem( key ) || '{}' ) || {};

							stored.lastEmail = email;
							store.setItem( key, JSON.stringify( stored ) );
						} catch ( error ) {
							// Remembering the address is a convenience only.
						}
					}

					return;
				}

				message.textContent = body && body.data && body.data.message
					? body.data.message
					: strings.failed || 'The progress could not be saved. Please try again.';
			} ).catch( function () {
				submit.disabled = false;
				message.textContent = strings.failed || 'The progress could not be saved. Please try again.';
			} );
		} );

		form.rfModal = overlay;
	}

	/**
	 * Bind Save and Resume behaviour to one form.
	 *
	 * @param {Element} form Form element.
	 */
	function init( form ) {
		if ( '1' === form.getAttribute( 'data-rf-save-resume-ready' ) ) {
			return;
		}

		form.setAttribute( 'data-rf-save-resume-ready', '1' );

		// Local drafts restore before the wizard boots (this script is
		// enqueued first) so the saved page can still be reopened.
		restoreDraft( form );
		bindDrafts( form );

		var row = form.querySelector( '[data-rf-save-resume-row]' );

		if ( ! row ) {
			return;
		}

		row.hidden = false;

		var button = row.querySelector( '[data-rf-save-resume-button]' );

		if ( button ) {
			button.addEventListener( 'click', function () {
				if ( form.rfSaveDraft ) {
					form.rfSaveDraft();
				}

				openModal( form );
			} );
		}

		var discard = form.querySelector( '[data-rf-resume-discard]' );

		if ( discard ) {
			discard.addEventListener( 'click', function () {
				clearValues( form );
				clearDraft( form );
				form.removeAttribute( 'data-rf-resume-token' );

				var notice = discard.closest( '[data-rf-resume-notice]' );

				if ( notice ) {
					notice.remove();
				}
			} );
		}
	}

	/**
	 * Bind every Save and Resume form under a root.
	 *
	 * @param {Element} root Scope to search (document when omitted).
	 */
	function boot( root ) {
		( root || document ).querySelectorAll( '.relevant-forms[data-rf-save-resume-nonce]' ).forEach( function ( form ) {
			init( form );
		} );
	}

	boot( document );

	// Allow re-runs on dynamically injected markup (Divi previews).
	( window.relevantFormsFrontend = window.relevantFormsFrontend || [] ).push( boot );
}() );
