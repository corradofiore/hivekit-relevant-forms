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

	/*
	 * Fields editor: a draggable list on the left, the selected field's
	 * editor on the right. Reordering moves the list item AND its editor
	 * panel so the submitted order (sentinel_fields) follows the list.
	 */
	var fieldList = document.getElementById( 'sf-fields-list' );
	var fieldEditors = document.getElementById( 'sf-fields-editors' );
	var fieldsEmpty = document.getElementById( 'sf-fields-empty' );
	var draggedItem = null;
	var fieldStrings = window.sentinelFormsAdmin && window.sentinelFormsAdmin.fieldStrings
		? window.sentinelFormsAdmin.fieldStrings
		: {};

	/**
	 * Select one field: highlight its list item and show its editor.
	 *
	 * @param {string} id Field id ('' clears the selection).
	 */
	function selectField( id ) {
		fieldList.querySelectorAll( '.sf-field-item' ).forEach( function ( item ) {
			var active = item.getAttribute( 'data-for' ) === id;

			item.classList.toggle( 'sf-field-item--active', active );
			item.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );

		fieldEditors.querySelectorAll( '.sf-field-editor' ).forEach( function ( editor ) {
			editor.hidden = editor.getAttribute( 'data-for' ) !== id;
		} );

		if ( fieldsEmpty ) {
			fieldsEmpty.hidden = id !== '';
		}
	}

	/**
	 * Keep the editor panels in the same order as the dragged list.
	 *
	 * @param {Element} item   Dragged list item.
	 * @param {Element} target Item it was moved around.
	 * @param {boolean} after  Whether the item landed after the target.
	 */
	function moveEditorWithItem( item, target, after ) {
		var editor = fieldEditors.querySelector( '[data-for="' + item.getAttribute( 'data-for' ) + '"]' );
		var targetEditor = fieldEditors.querySelector( '[data-for="' + target.getAttribute( 'data-for' ) + '"]' );

		if ( ! editor || ! targetEditor ) {
			return;
		}

		fieldEditors.insertBefore( editor, after ? targetEditor.nextSibling : targetEditor );
	}

	/**
	 * Remove one field: its list item and its editor panel.
	 *
	 * @param {Element} item List item to remove.
	 */
	function removeField( item ) {
		var id = item.getAttribute( 'data-for' );
		var editor = fieldEditors.querySelector( '[data-for="' + id + '"]' );
		var wasActive = item.classList.contains( 'sf-field-item--active' );

		item.remove();

		if ( editor ) {
			editor.remove();
		}

		if ( wasActive ) {
			var next = fieldList.querySelector( '.sf-field-item' );

			selectField( next ? next.getAttribute( 'data-for' ) : '' );
		}
	}

	/**
	 * Add a new field from the type template (list item + editor panel).
	 *
	 * @param {string} type Field type.
	 */
	function addField( type ) {
		var template = document.getElementById( 'sf-field-template-' + type );
		var id, wrapper, item, editor, labelInput;

		if ( ! template || ! fieldList || ! fieldEditors ) {
			return;
		}

		id = generateId();
		wrapper = document.createElement( 'div' );
		wrapper.innerHTML = template.innerHTML.replace( /\{ID\}/g, id );

		item = wrapper.querySelector( '.sf-field-item' );
		editor = wrapper.querySelector( '.sf-field-editor' );

		if ( ! item || ! editor ) {
			return;
		}

		fieldList.appendChild( item );
		fieldEditors.appendChild( editor );

		selectField( id );
		item.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );

		labelInput = editor.querySelector( '.sf-input-label' );

		if ( labelInput ) {
			labelInput.focus();
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( 'button.sf-remove' );
		var item;

		if ( ! button ) {
			return;
		}

		item = button.closest( '.sf-field-item' );

		if ( item ) {
			removeField( item );
		}
	} );

	if ( fieldList && fieldEditors ) {
		fieldList.addEventListener( 'click', function ( event ) {
			var item = event.target.closest( '.sf-field-item' );

			if ( item ) {
				selectField( item.getAttribute( 'data-for' ) );
			}
		} );

		fieldList.addEventListener( 'keydown', function ( event ) {
			var item = event.target.closest( '.sf-field-item' );

			if ( item && ( event.key === 'Enter' || event.key === ' ' ) ) {
				event.preventDefault();
				selectField( item.getAttribute( 'data-for' ) );
			}
		} );

		fieldList.addEventListener( 'dragstart', function ( event ) {
			var item = event.target.closest( '.sf-field-item' );

			if ( ! item ) {
				return;
			}

			draggedItem = item;
			item.classList.add( 'sf-field-item--dragging' );

			if ( event.dataTransfer ) {
				event.dataTransfer.effectAllowed = 'move';
				// Firefox needs data for the drag to start.
				event.dataTransfer.setData( 'text/plain', item.getAttribute( 'data-for' ) );
			}
		} );

		fieldList.addEventListener( 'dragover', function ( event ) {
			var target = event.target.closest( '.sf-field-item' );
			var rect, after;

			if ( ! draggedItem || ! target || target === draggedItem ) {
				return;
			}

			event.preventDefault();

			if ( event.dataTransfer ) {
				event.dataTransfer.dropEffect = 'move';
			}

			rect = target.getBoundingClientRect();
			after = event.clientY > rect.top + ( rect.height / 2 );

			fieldList.insertBefore( draggedItem, after ? target.nextSibling : target );
			moveEditorWithItem( draggedItem, target, after );
		} );

		fieldList.addEventListener( 'drop', function ( event ) {
			event.preventDefault();
		} );

		fieldList.addEventListener( 'dragend', function () {
			if ( draggedItem ) {
				draggedItem.classList.remove( 'sf-field-item--dragging' );
				draggedItem = null;
			}
		} );

		// The list item shows the field name: keep it in sync with the label.
		fieldEditors.addEventListener( 'input', function ( event ) {
			var input = event.target;
			var editor, item, name;

			if ( ! input.name || input.name.indexOf( '[label]' ) === -1 ) {
				return;
			}

			editor = input.closest( '.sf-field-editor' );

			if ( ! editor ) {
				return;
			}

			item = fieldList.querySelector( '[data-for="' + editor.getAttribute( 'data-for' ) + '"]' );

			if ( ! item ) {
				return;
			}

			name = item.querySelector( '.sf-field-item__name' );

			if ( name ) {
				name.textContent = input.value.trim() || fieldStrings.untitled || 'Untitled field';
			}
		} );

		// Select the first field (or show the empty state) on load.
		var firstItem = fieldList.querySelector( '.sf-field-item' );

		if ( firstItem ) {
			selectField( firstItem.getAttribute( 'data-for' ) );
		} else if ( fieldsEmpty ) {
			fieldsEmpty.hidden = false;
		}
	}

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
	var confirmationMode = document.getElementById( 'sentinel-confirmation-mode' );

	if ( confirmationMode ) {
		var confirmationRows = {
			message: document.getElementById( 'sf-confirmation-message-row' ),
			url: document.getElementById( 'sf-confirmation-url-row' ),
			page: document.getElementById( 'sf-confirmation-page-row' )
		};

		/**
		 * Show the row that matches the selected confirmation mode.
		 */
		function syncConfirmationRows() {
			Object.keys( confirmationRows ).forEach( function ( mode ) {
				if ( confirmationRows[ mode ] ) {
					confirmationRows[ mode ].hidden = mode !== confirmationMode.value;
				}
			} );
		}

		confirmationMode.addEventListener( 'change', syncConfirmationRows );

		syncConfirmationRows();
	}

	/*
	 * Priority SMS recipient: the field is active only while the option is
	 * checked, and a new or changed number must pass a 6-digit code check
	 * (the save handler refuses unverified numbers).
	 */
	var smsToggle = document.getElementById( 'sentinel-ai-sms-alert' );
	var smsPhone = document.getElementById( 'sentinel-ai-sms-phone' );

	if ( smsToggle && smsPhone && window.sentinelFormsAdmin ) {
		( function () {
			var settings = window.sentinelFormsAdmin;
			var strings = settings.smsStrings || {};
			var sendButton = document.getElementById( 'sf-sms-send-code' );
			var verifyRow = document.getElementById( 'sf-sms-code-row' );
			var codeInput = document.getElementById( 'sf-sms-code' );
			var verifyButton = document.getElementById( 'sf-sms-verify-code' );
			var phoneMessage = document.getElementById( 'sf-sms-phone-message' );
			var codeMessage = document.getElementById( 'sf-sms-code-message' );

			/**
			 * Show one feedback message directly below its field.
			 *
			 * @param {Element} element Message paragraph.
			 * @param {string}  text    Message text ('' hides the line).
			 * @param {string}  tone    'error' | 'info' | 'ok' | ''.
			 */
			function setMessage( element, text, tone ) {
				element.textContent = text || '';
				element.className = 'sf-sms-message' + ( text && tone ? ' sf-sms-message--' + tone : '' );
				element.hidden = ! text;
			}

			/**
			 * Mark the field verified only when it matches the stored number.
			 */
			function syncVerifiedState() {
				if ( phoneMessage.getAttribute( 'data-error' ) === '1' ) {
					// Keep the save error visible until the number is edited.
					return;
				}

				var stored = smsPhone.getAttribute( 'data-stored' ) || '';
				var current = smsPhone.value.trim();

				if ( current !== '' && current === stored ) {
					setMessage( phoneMessage, strings.verified, 'ok' );
				} else if ( current !== '' ) {
					setMessage( phoneMessage, strings.unverified, 'info' );
				} else {
					setMessage( phoneMessage, '', '' );
				}
			}

			/**
			 * Enable the field only while the option is checked.
			 */
			function syncEnabled() {
				var on = smsToggle.checked;

				smsPhone.disabled = ! on;
				sendButton.disabled = ! on;

				if ( ! on ) {
					verifyRow.hidden = true;
					setMessage( phoneMessage, '', '' );
					setMessage( codeMessage, '', '' );
					return;
				}

				syncVerifiedState();
			}

			/**
			 * POST one admin-ajax action and parse the JSON envelope.
			 *
			 * @param {string} action Admin-ajax action.
			 * @param {string} nonce  Nonce for the action.
			 * @param {Object} extra  Additional fields.
			 * @return {Promise} Parsed response body.
			 */
			function post( action, nonce, extra ) {
				var body = new window.FormData();

				body.append( 'action', action );
				body.append( '_wpnonce', nonce );

				Object.keys( extra ).forEach( function ( key ) {
					body.append( key, extra[ key ] );
				} );

				return window.fetch( settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				} ).then( function ( response ) {
					return response.json();
				} );
			}

			/**
			 * Error message from a failed envelope.
			 *
			 * @param {Object} body Parsed response body.
			 * @return {string} Message to show.
			 */
			function errorText( body ) {
				if ( body && body.data && body.data.message ) {
					return body.data.message;
				}

				return strings.requestFailed;
			}

			smsToggle.addEventListener( 'change', syncEnabled );

			smsPhone.addEventListener( 'input', function () {
				verifyRow.hidden = true;
				phoneMessage.removeAttribute( 'data-error' );
				setMessage( codeMessage, '', '' );
				syncVerifiedState();
			} );

			sendButton.addEventListener( 'click', function () {
				var phone = smsPhone.value.trim();

				if ( phone === '' ) {
					setMessage( phoneMessage, strings.checkPhone, 'error' );
					return;
				}

				setMessage( phoneMessage, strings.sending, 'info' );

				post( 'sentinel_forms_sms_send', settings.smsSendNonce, { phone: phone } )
					.then( function ( body ) {
						if ( ! body || ! body.success ) {
							setMessage( phoneMessage, errorText( body ), 'error' );
							return;
						}

						if ( body.data.phone ) {
							smsPhone.value = body.data.phone;
						}

						phoneMessage.removeAttribute( 'data-error' );
						setMessage( phoneMessage, '', '' );

						var message = strings.codeSent;

						if ( body.data.dev_code ) {
							message += ' ' + strings.devCode + ' ' + body.data.dev_code;
						}

						setMessage( codeMessage, message, 'info' );
						verifyRow.hidden = false;
						codeInput.value = '';
						codeInput.focus();
					} )
					.catch( function () {
						setMessage( phoneMessage, strings.requestFailed, 'error' );
					} );
			} );

			verifyButton.addEventListener( 'click', function () {
				post(
					'sentinel_forms_sms_verify',
					settings.smsVerifyNonce,
					{ phone: smsPhone.value.trim(), code: codeInput.value.trim() }
				)
					.then( function ( body ) {
						if ( ! body || ! body.success ) {
							setMessage( codeMessage, errorText( body ), 'error' );
							return;
						}

						if ( body.data.phone ) {
							smsPhone.value = body.data.phone;
							smsPhone.setAttribute( 'data-stored', body.data.phone );
						}

						verifyRow.hidden = true;
						phoneMessage.removeAttribute( 'data-error' );
						setMessage( codeMessage, '', '' );
						setMessage( phoneMessage, strings.verified, 'ok' );
					} )
					.catch( function () {
						setMessage( codeMessage, strings.requestFailed, 'error' );
					} );
			} );

			syncEnabled();
		} )();
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
