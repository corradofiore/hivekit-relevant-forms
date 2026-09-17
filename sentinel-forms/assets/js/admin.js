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
	var fieldPreviews = document.getElementById( 'sf-fields-preview' );
	var fieldVisibility = document.getElementById( 'sf-fields-visibility' );
	var fieldsEmpty = document.getElementById( 'sf-fields-empty' );
	var fieldsTitle = document.getElementById( 'sf-fields-title' );
	var fieldsTitleDefault = fieldsTitle ? fieldsTitle.textContent : '';
	var draggedItem = null;
	var fieldStrings = window.sentinelFormsAdmin && window.sentinelFormsAdmin.fieldStrings
		? window.sentinelFormsAdmin.fieldStrings
		: {};
	var visibilityStrings = window.sentinelFormsAdmin && window.sentinelFormsAdmin.visibilityStrings
		? window.sentinelFormsAdmin.visibilityStrings
		: { shown: 'Shown when: %s', hidden: 'Hidden when: %s', and: 'and', or: 'or', notAbove: 'not above', selectField: '', selectValue: '' };
	var visibilityOperators = window.sentinelFormsAdmin && window.sentinelFormsAdmin.visibilityOperators
		? window.sentinelFormsAdmin.visibilityOperators
		: {};
	var choiceOperators = window.sentinelFormsAdmin && window.sentinelFormsAdmin.choiceOperators
		? window.sentinelFormsAdmin.choiceOperators
		: [ 'is', 'is_not', 'empty', 'not_empty' ];

	/**
	 * Name shown for a field in the list ('' when there is none).
	 *
	 * @param {string} id Field id.
	 * @return {string} Field name.
	 */
	function fieldDisplayName( id ) {
		var item = id ? fieldList.querySelector( '.sf-field-item[data-for="' + id + '"]' ) : null;
		var name = item ? item.querySelector( '.sf-field-item__name' ) : null;

		return name ? name.textContent : '';
	}

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

		if ( fieldPreviews ) {
			fieldPreviews.querySelectorAll( '.sf-field-preview' ).forEach( function ( preview ) {
				preview.hidden = preview.getAttribute( 'data-for' ) !== id;
			} );
		}

		if ( fieldVisibility ) {
			fieldVisibility.querySelectorAll( '.sf-field-visibility' ).forEach( function ( block ) {
				block.hidden = block.getAttribute( 'data-for' ) !== id;
			} );
		}

		if ( fieldsEmpty ) {
			fieldsEmpty.hidden = id !== '';
		}

		// The section header carries the selected field's name.
		if ( fieldsTitle ) {
			fieldsTitle.textContent = fieldDisplayName( id ) || fieldsTitleDefault;
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

		if ( fieldPreviews ) {
			var preview = fieldPreviews.querySelector( '.sf-field-preview[data-for="' + item.getAttribute( 'data-for' ) + '"]' );
			var targetPreview = fieldPreviews.querySelector( '.sf-field-preview[data-for="' + target.getAttribute( 'data-for' ) + '"]' );

			if ( preview && targetPreview ) {
				fieldPreviews.insertBefore( preview, after ? targetPreview.nextSibling : targetPreview );
			}
		}

		if ( fieldVisibility ) {
			var block = fieldVisibility.querySelector( '.sf-field-visibility[data-for="' + item.getAttribute( 'data-for' ) + '"]' );
			var targetBlock = fieldVisibility.querySelector( '.sf-field-visibility[data-for="' + target.getAttribute( 'data-for' ) + '"]' );

			if ( block && targetBlock ) {
				fieldVisibility.insertBefore( block, after ? targetBlock.nextSibling : targetBlock );
			}
		}
	}

	/**
	 * Show or hide the whole preview section (an empty form has none).
	 *
	 * @param {boolean} visible Whether the section should be visible.
	 */
	function togglePreviewPanel( visible ) {
		var title = document.getElementById( 'sf-fields-preview-title' );

		if ( fieldPreviews ) {
			fieldPreviews.hidden = ! visible;
		}

		if ( title ) {
			title.hidden = ! visible;
		}
	}

	/**
	 * Show or hide the whole visibility section (an empty form has none).
	 *
	 * @param {boolean} visible Whether the section should be visible.
	 */
	function toggleVisibilityPanel( visible ) {
		var title = document.getElementById( 'sf-fields-visibility-title' );

		if ( fieldVisibility ) {
			fieldVisibility.hidden = ! visible;
		}

		if ( title ) {
			title.hidden = ! visible;
		}
	}

	/**
	 * Parse the choices textarea: one choice per line, optional value after
	 * the first "|" (same rule as the PHP renderers).
	 *
	 * @param {string} raw Textarea value.
	 * @return {Array} Choices as {label, value} pairs.
	 */
	function parseChoiceLines( raw ) {
		var choices = [];

		raw.split( '\n' ).forEach( function ( line ) {
			var pipe, label, value;

			line = line.trim();

			if ( '' === line ) {
				return;
			}

			pipe = line.indexOf( '|' );
			label = ( pipe === -1 ? line : line.slice( 0, pipe ) ).trim();
			value = pipe === -1 ? label : ( line.slice( pipe + 1 ).trim() || label );

			if ( '' === label ) {
				return;
			}

			choices.push( { label: label, value: value } );
		} );

		return choices;
	}

	/**
	 * Rebuild the sample options of a select/radio/checkbox field.
	 *
	 * @param {Element} wrapper Sample field wrapper.
	 * @param {string}  baseId  Base DOM id of the sample field.
	 * @param {string}  type    Field type.
	 * @param {string}  fieldId Field id (for newly created input names).
	 * @param {Array}   choices Parsed choices.
	 */
	function rebuildSampleChoices( wrapper, baseId, type, fieldId, choices ) {
		var select = wrapper.querySelector( 'select' );
		var group = wrapper.querySelector( '.sentinel-forms-choice-group' );
		var name = null;
		var checked = [];

		if ( select ) {
			// Keep the localized "— Select —" placeholder option.
			while ( select.options.length > 1 ) {
				select.remove( 1 );
			}

			choices.forEach( function ( choice ) {
				var option = document.createElement( 'option' );

				option.setAttribute( 'value', choice.value );
				option.textContent = choice.label;
				select.appendChild( option );
			} );

			return;
		}

		if ( ! group ) {
			return;
		}

		group.querySelectorAll( 'input' ).forEach( function ( input ) {
			if ( ! name ) {
				name = input.getAttribute( 'name' );
			}

			if ( input.checked ) {
				checked.push( input.value );
			}
		} );

		name = name || ( 'sentinel_field_' + fieldId + ( 'checkbox' === type ? '[]' : '' ) );

		group.textContent = '';

		choices.forEach( function ( choice, index ) {
			var item = document.createElement( 'span' );
			var input = document.createElement( 'input' );
			var label = document.createElement( 'label' );

			item.className = 'sentinel-forms-choice';

			input.setAttribute( 'type', 'checkbox' === type ? 'checkbox' : 'radio' );
			input.setAttribute( 'name', name );
			input.setAttribute( 'id', baseId + '-' + index );
			input.setAttribute( 'value', choice.value );
			input.setAttribute( 'form', 'sentinel-forms-preview' );

			if ( checked.indexOf( choice.value ) !== -1 ) {
				input.checked = true;
			}

			label.setAttribute( 'for', baseId + '-' + index );
			label.textContent = choice.label;

			item.appendChild( input );
			item.appendChild( label );
			group.appendChild( item );
		} );
	}

	/**
	 * Mirror editor changes into the field preview (Preview panel below the
	 * fields editor): label, required, placeholder, help text, default value
	 * and choices update while they are typed, following the same markup the
	 * front-end field renderers produce.
	 *
	 * @param {Element} editor Field editor panel.
	 */
	function syncSample( editor ) {
		var preview = fieldPreviews ? fieldPreviews.querySelector( '.sf-field-preview[data-for="' + editor.getAttribute( 'data-for' ) + '"]' ) : null;
		var wrapper = preview ? preview.querySelector( '.sentinel-forms-field' ) : null;
		var baseId = editor.getAttribute( 'data-control-id' );
		var type = editor.getAttribute( 'data-type' );
		var labelInput = editor.querySelector( '[name$="[label]"]' );
		var placeholderInput = editor.querySelector( '[name$="[placeholder]"]' );
		var helpInput = editor.querySelector( '[name$="[help_text]"]' );
		var defaultInput = editor.querySelector( '[name$="[default_value]"]' );
		var maxLengthInput = editor.querySelector( '[name$="[max_length]"]' );
		var requiredInput = editor.querySelector( 'input[type="checkbox"][name$="[required]"]' );
		var choicesInput = editor.querySelector( 'textarea[name$="[choices]"]' );
		var required = !! ( requiredInput && requiredInput.checked );
		var fieldset, control, label, marker, help, anchor, counter;

		if ( ! preview || ! wrapper || ! baseId ) {
			return;
		}

		// Section separators have no control: only the heading text is editable.
		if ( 'section' === type ) {
			var heading = wrapper.querySelector( '.sentinel-forms-section-title' );
			var headingText = labelInput ? labelInput.value : '';

			if ( '' === headingText ) {
				if ( heading ) {
					heading.remove();
				}
			} else {
				if ( ! heading ) {
					heading = document.createElement( 'h3' );
					heading.className = 'sentinel-forms-section-title';
					wrapper.insertBefore( heading, wrapper.firstChild );
				}

				heading.textContent = headingText;
			}

			return;
		}

		fieldset = wrapper.querySelector( 'fieldset' );
		control = document.getElementById( baseId );
		label = null;

		wrapper.querySelectorAll( 'label' ).forEach( function ( candidate ) {
			if ( ! label && candidate.getAttribute( 'for' ) === baseId ) {
				label = candidate;
			}
		} );

		// Label text plus, outside consent fields, the required asterisk.
		if ( labelInput ) {
			if ( 'consent' === type ) {
				if ( label ) {
					label.textContent = labelInput.value;
				}
			} else if ( '' === labelInput.value ) {
				if ( label && label.parentNode === wrapper ) {
					label.remove();
					label = null;
				}
			} else {
				if ( ! label ) {
					label = document.createElement( 'label' );
					label.setAttribute( 'for', baseId );
					wrapper.insertBefore( label, wrapper.firstChild );
				}

				label.textContent = labelInput.value;

				if ( required ) {
					marker = document.createElement( 'span' );
					marker.className = 'sentinel-forms-required';
					marker.textContent = '*';
					label.appendChild( document.createTextNode( ' ' ) );
					label.appendChild( marker );
				}
			}
		}

		// Required state on the control itself (fieldset for choice groups).
		if ( fieldset ) {
			if ( required ) {
				fieldset.setAttribute( 'aria-required', 'true' );
			} else {
				fieldset.removeAttribute( 'aria-required' );
			}
		}

		if ( control ) {
			if ( required ) {
				control.setAttribute( 'required', 'required' );
			} else {
				control.removeAttribute( 'required' );
			}
		}

		// Placeholder, default value and maxlength on the text-like controls.
		if ( placeholderInput || defaultInput || maxLengthInput ) {
			wrapper.querySelectorAll( 'input, textarea' ).forEach( function ( element ) {
				var tag = element.tagName.toLowerCase();
				var controlType = ( element.getAttribute( 'type' ) || '' ).toLowerCase();
				var isTextLike = 'textarea' === tag || [ 'text', 'email', 'tel', 'url', 'number', 'search', 'date' ].indexOf( controlType ) !== -1;

				if ( ! isTextLike ) {
					return;
				}

				if ( placeholderInput && 'date' !== controlType ) {
					if ( '' === placeholderInput.value ) {
						element.removeAttribute( 'placeholder' );
					} else {
						element.setAttribute( 'placeholder', placeholderInput.value );
					}
				}

				if ( defaultInput ) {
					if ( 'textarea' === tag ) {
						element.textContent = defaultInput.value;
					} else {
						element.setAttribute( 'value', defaultInput.value );
						element.value = defaultInput.value;
					}
				}

				if ( maxLengthInput ) {
					var maxLength = parseInt( maxLengthInput.value, 10 );

					if ( ! isNaN( maxLength ) && maxLength > 0 ) {
						element.setAttribute( 'maxlength', String( maxLength ) );
					} else {
						element.removeAttribute( 'maxlength' );
					}
				}

				// Paragraph fields carry a realtime counter: mirror the
				// control's limit and current value into it.
				counter = wrapper.querySelector( '.sentinel-forms-char-count[data-sf-count-for="' + element.id + '"]' );

				if ( counter ) {
					if ( element.maxLength > 0 ) {
						counter.hidden = false;
						counter.textContent = element.value.length + '/' + element.maxLength;
					} else {
						counter.hidden = true;
						counter.textContent = String( element.value.length );
					}
				}
			} );
		}

		// Help text paragraph (id "{control-id}-help") and its aria wiring.
		if ( helpInput ) {
			help = document.getElementById( baseId + '-help' );

			if ( '' === helpInput.value ) {
				if ( help ) {
					help.remove();
					help = null;
				}
			} else {
				if ( ! help ) {
					help = document.createElement( 'p' );
					help.className = 'sentinel-forms-help';
					help.id = baseId + '-help';

					// Paragraph fields end with their character counter: the
					// help text goes below it, like the server rendering.
					anchor = wrapper.querySelector( '.sentinel-forms-char-count[data-sf-count-for="' + baseId + '"]' )
						|| fieldset
						|| control;

					if ( anchor && anchor.parentNode ) {
						anchor.insertAdjacentElement( 'afterend', help );
					} else {
						wrapper.appendChild( help );
					}
				}

				help.textContent = helpInput.value;
			}

			wrapper.querySelectorAll( 'input, select, textarea' ).forEach( function ( element ) {
				if ( '' === helpInput.value ) {
					element.removeAttribute( 'aria-describedby' );
				} else {
					element.setAttribute( 'aria-describedby', baseId + '-help' );
				}
			} );
		}

		if ( choicesInput ) {
			rebuildSampleChoices( wrapper, baseId, type, editor.getAttribute( 'data-for' ), parseChoiceLines( choicesInput.value ) );
		}

		// The preview must never join the save form, or browser validation
		// (required) blocks the whole submission.
		preview.querySelectorAll( 'input, select, textarea' ).forEach( function ( element ) {
			element.setAttribute( 'form', 'sentinel-forms-preview' );
		} );
	}

	/*
	 * Field visibility rules editor: a default state plus groups of rules
	 * (joined with AND inside a group, OR across groups). Source dropdowns
	 * list the fields above the edited one; the value control switches
	 * between a text input and a choices dropdown for choice sources.
	 */

	/**
	 * List items that sit above the given field (the allowed sources).
	 *
	 * @param {string} fieldId Field id.
	 * @return {Array} List items.
	 */
	function previousFieldItems( fieldId ) {
		var items = Array.prototype.slice.call( fieldList.querySelectorAll( '.sf-field-item' ) );
		var result = [];

		for ( var index = 0; index < items.length; index++ ) {
			if ( items[ index ].getAttribute( 'data-for' ) === fieldId ) {
				break;
			}

			result.push( items[ index ] );
		}

		return result;
	}

	/**
	 * Type of a field, read from its editor panel.
	 *
	 * @param {string} fieldId Field id.
	 * @return {string} Field type.
	 */
	function fieldTypeOf( fieldId ) {
		var editor = fieldEditors.querySelector( '.sf-field-editor[data-for="' + fieldId + '"]' );

		return editor ? ( editor.getAttribute( 'data-type' ) || '' ) : '';
	}

	/**
	 * Choices of a field, parsed live from its editor textarea.
	 *
	 * @param {string} fieldId Field id.
	 * @return {Array} Choices as {label, value} pairs.
	 */
	function fieldChoicesOf( fieldId ) {
		var editor = fieldEditors.querySelector( '.sf-field-editor[data-for="' + fieldId + '"]' );
		var choicesInput = editor ? editor.querySelector( 'textarea[name$="[choices]"]' ) : null;

		return choicesInput ? parseChoiceLines( choicesInput.value ) : [];
	}

	/**
	 * Whether a field type uses fixed choices.
	 *
	 * @param {string} type Field type.
	 * @return {boolean} Whether the type is choice-based.
	 */
	function isChoiceSource( type ) {
		return [ 'select', 'radio', 'checkbox' ].indexOf( type ) !== -1;
	}

	/**
	 * Replace a select's options (labels via textContent, so no escaping).
	 *
	 * @param {Element} select Target select.
	 * @param {Array}   items  Options as {value, label} pairs.
	 */
	function setSelectOptions( select, items ) {
		select.textContent = '';

		items.forEach( function ( item ) {
			var option = document.createElement( 'option' );

			option.setAttribute( 'value', item.value );
			option.textContent = item.label;
			select.appendChild( option );
		} );
	}

	/**
	 * Rebuild the source dropdown of one rule (fields above + orphans).
	 *
	 * @param {Element} block Visibility block.
	 * @param {Element} rule  Rule row.
	 */
	function refreshSourceOptions( block, rule ) {
		var select = rule.querySelector( '.sf-visibility-source' );
		var current = select.value;
		var placeholder = select.options.length ? select.options[ 0 ].textContent : visibilityStrings.selectField;
		var items = [ { value: '', label: placeholder } ];
		var known = { '': true };

		previousFieldItems( block.getAttribute( 'data-for' ) ).forEach( function ( item ) {
			var id = item.getAttribute( 'data-for' );

			if ( 'section' === fieldTypeOf( id ) || known[ id ] ) {
				return;
			}

			known[ id ] = true;
			items.push( { value: id, label: String( fieldDisplayName( id ) || id ).trim() || id } );
		} );

		// Keep references that are no longer above (imports, reorders).
		if ( current && ! known[ current ] ) {
			var label = String( fieldDisplayName( current ) || current ).trim() || current;

			items.push( { value: current, label: label + ' (' + visibilityStrings.notAbove + ')' } );
		}

		setSelectOptions( select, items );
		select.value = current;
	}

	/**
	 * Rebuild the operator dropdown for one rule.
	 *
	 * @param {Element} rule       Rule row.
	 * @param {string}  sourceType Source field type.
	 */
	function refreshOperatorOptions( rule, sourceType ) {
		var select = rule.querySelector( '.sf-visibility-operator' );
		var current = select.value;
		var tokens = isChoiceSource( sourceType ) ? choiceOperators : Object.keys( visibilityOperators );
		var items = [];

		tokens.forEach( function ( token ) {
			items.push( { value: token, label: visibilityOperators[ token ] || token } );
		} );

		if ( current && tokens.indexOf( current ) === -1 ) {
			items.push( { value: current, label: visibilityOperators[ current ] || current } );
		}

		setSelectOptions( select, items );

		if ( current ) {
			select.value = current;
		} else if ( items.length ) {
			select.value = items[ 0 ].value;
		}
	}

	/**
	 * Rebuild the value control for one rule (input or choices dropdown).
	 *
	 * @param {Element} rule       Rule row.
	 * @param {string}  sourceType Source field type.
	 */
	function refreshValueControl( rule, sourceType ) {
		var holder = rule.querySelector( '[data-value]' );
		var existing = holder.querySelector( 'input, select' );
		var current = existing ? existing.value : '';
		var source = rule.querySelector( '.sf-visibility-source' );
		var match = ( source.getAttribute( 'name' ) || '' ).match( /^(.*)\[field\]$/ );
		var control;

		holder.textContent = '';

		if ( isChoiceSource( sourceType ) ) {
			control = document.createElement( 'select' );

			var items = [ { value: '', label: visibilityStrings.selectValue } ];
			var known = { '': true };

			fieldChoicesOf( source.value ).forEach( function ( choice ) {
				if ( known[ choice.value ] ) {
					return;
				}

				known[ choice.value ] = true;
				items.push( { value: choice.value, label: choice.label } );
			} );

			if ( current && ! known[ current ] ) {
				items.push( { value: current, label: current } );
			}

			setSelectOptions( control, items );
			control.value = current;
		} else {
			control = document.createElement( 'input' );
			control.setAttribute( 'type', 'text' );
			control.value = current;
		}

		if ( match ) {
			control.setAttribute( 'name', match[ 1 ] + '[value]' );
		}

		holder.appendChild( control );
		syncValueHolderVisibility( rule );
	}

	/**
	 * Hide the value control for valueless operators (empty / not empty).
	 *
	 * @param {Element} rule Rule row.
	 */
	function syncValueHolderVisibility( rule ) {
		var holder = rule.querySelector( '[data-value]' );
		var operator = rule.querySelector( '.sf-visibility-operator' );

		if ( holder ) {
			holder.hidden = !! operator && [ 'empty', 'not_empty' ].indexOf( operator.value ) !== -1;
		}
	}

	/**
	 * Append a new rule row to a group.
	 *
	 * @param {Element} block Visibility block.
	 * @param {Element} group Group element.
	 */
	function addVisibilityRule( block, group ) {
		var template = document.getElementById( 'sf-visibility-rule-template' );

		if ( ! template ) {
			return;
		}

		if ( ! group.hasAttribute( 'data-index' ) ) {
			var maxGroup = -1;

			block.querySelectorAll( '[data-group]' ).forEach( function ( candidate ) {
				var index = parseInt( candidate.getAttribute( 'data-index' ) || '-1', 10 );

				if ( index > maxGroup ) {
					maxGroup = index;
				}
			} );

			group.setAttribute( 'data-index', String( maxGroup + 1 ) );
		}

		var maxRule = -1;

		group.querySelectorAll( '[data-rule] [name]' ).forEach( function ( control ) {
			var match = ( control.getAttribute( 'name' ) || '' ).match( /\[groups\]\[\d+\]\[(\d+)\]/ );

			if ( match ) {
				maxRule = Math.max( maxRule, parseInt( match[ 1 ], 10 ) );
			}
		} );

		var html = template.innerHTML
			.replace( /\{FIELDID\}/g, block.getAttribute( 'data-for' ) )
			.replace( /\{GROUP\}/g, group.getAttribute( 'data-index' ) )
			.replace( /\{RULE\}/g, String( maxRule + 1 ) );
		var wrapper = document.createElement( 'div' );

		wrapper.innerHTML = html;

		var rule = wrapper.querySelector( '[data-rule]' );

		group.querySelector( '[data-rules]' ).appendChild( rule );

		refreshSourceOptions( block, rule );
		refreshOperatorOptions( rule, fieldTypeOf( rule.querySelector( '.sf-visibility-source' ).value ) );
		refreshValueControl( rule, fieldTypeOf( rule.querySelector( '.sf-visibility-source' ).value ) );
	}

	/**
	 * Append a new rule group (with one starter rule inside).
	 *
	 * @param {Element} block Visibility block.
	 */
	function addVisibilityGroup( block ) {
		var template = document.getElementById( 'sf-visibility-group-template' );

		if ( ! template ) {
			return;
		}

		var wrapper = document.createElement( 'div' );

		wrapper.innerHTML = template.innerHTML;

		var group = wrapper.querySelector( '[data-group]' );

		block.querySelector( '[data-groups]' ).appendChild( group );

		addVisibilityRule( block, group );
		refreshVisibilityJoins( block );
		syncVisibilityNote( block );
	}

	/**
	 * Show the "or" separator on every group but the first.
	 *
	 * @param {Element} block Visibility block.
	 */
	function refreshVisibilityJoins( block ) {
		block.querySelectorAll( '[data-group]' ).forEach( function ( group, index ) {
			var join = group.querySelector( '.sf-field-visibility__join' );

			if ( join ) {
				join.hidden = 0 === index;
			}
		} );
	}

	/**
	 * Human summary of a block's rules ('' while incomplete).
	 *
	 * @param {Element} block Visibility block.
	 * @return {string} Summary sentence.
	 */
	function visibilityNote( block ) {
		var defaultSelect = block.querySelector( 'select[name$="[visibility][default]"]' );
		var groups = [];

		block.querySelectorAll( '[data-group]' ).forEach( function ( group ) {
			var rules = [];

			group.querySelectorAll( '[data-rule]' ).forEach( function ( rule ) {
				var source = rule.querySelector( '.sf-visibility-source' );
				var operator = rule.querySelector( '.sf-visibility-operator' );

				if ( ! source || ! source.value || ! operator || ! operator.value ) {
					return;
				}

				var text = source.options[ source.selectedIndex ].textContent + ' ' + operator.options[ operator.selectedIndex ].textContent;

				if ( 'empty' !== operator.value && 'not_empty' !== operator.value ) {
					var value = rule.querySelector( '[data-value] input, [data-value] select' );
					var valueText = '';

					if ( value && 'SELECT' === value.tagName && value.selectedIndex >= 0 ) {
						valueText = value.options[ value.selectedIndex ].textContent;
					} else if ( value ) {
						valueText = value.value;
					}

					if ( '' === valueText ) {
						return;
					}

					text += ' ' + valueText;
				}

				rules.push( text );
			} );

			if ( rules.length ) {
				groups.push( rules.join( ' ' + visibilityStrings.and + ' ' ) );
			}
		} );

		if ( ! groups.length ) {
			return '';
		}

		var template = defaultSelect && 'hidden' === defaultSelect.value ? visibilityStrings.shown : visibilityStrings.hidden;

		return template.replace( '%s', groups.join( ' ' + visibilityStrings.or + ' ' ) );
	}

	/**
	 * Mirror a block's rules into its preview note.
	 *
	 * @param {Element} block Visibility block.
	 */
	function syncVisibilityNote( block ) {
		var preview = fieldPreviews ? fieldPreviews.querySelector( '.sf-field-preview[data-for="' + block.getAttribute( 'data-for' ) + '"]' ) : null;

		if ( ! preview ) {
			return;
		}

		var note = preview.querySelector( '.sf-field-preview__visibility' );
		var text = visibilityNote( block );

		if ( '' === text ) {
			if ( note ) {
				note.remove();
			}

			return;
		}

		if ( ! note ) {
			note = document.createElement( 'p' );
			note.className = 'sf-field-preview__visibility';
			preview.appendChild( note );
		}

		note.textContent = text;
	}

	/**
	 * Refresh every preview note (labels changed, rules edited).
	 */
	function syncAllVisibilityNotes() {
		if ( ! fieldVisibility ) {
			return;
		}

		fieldVisibility.querySelectorAll( '.sf-field-visibility' ).forEach( function ( block ) {
			syncVisibilityNote( block );
		} );
	}

	/**
	 * Refresh the source dropdowns of every rule (order/name changes).
	 */
	function refreshVisibilitySources() {
		if ( ! fieldVisibility ) {
			return;
		}

		fieldVisibility.querySelectorAll( '.sf-field-visibility' ).forEach( function ( block ) {
			block.querySelectorAll( '[data-rule]' ).forEach( function ( rule ) {
				refreshSourceOptions( block, rule );
				syncValueHolderVisibility( rule );
			} );
		} );
	}

	/**
	 * Refresh the choices dropdowns that read from one field.
	 *
	 * @param {string} fieldId Source field id.
	 */
	function refreshChoiceValueControls( fieldId ) {
		if ( ! fieldVisibility ) {
			return;
		}

		fieldVisibility.querySelectorAll( '.sf-field-visibility' ).forEach( function ( block ) {
			block.querySelectorAll( '[data-rule]' ).forEach( function ( rule ) {
				var source = rule.querySelector( '.sf-visibility-source' );

				if ( source && source.value === fieldId ) {
					refreshValueControl( rule, fieldTypeOf( fieldId ) );
				}
			} );
		} );
	}

	if ( fieldVisibility ) {
		fieldVisibility.addEventListener( 'click', function ( event ) {
			var block = event.target.closest( '.sf-field-visibility' );
			var group = event.target.closest( '[data-group]' );
			var rule;

			if ( ! block ) {
				return;
			}

			if ( event.target.closest( '.sf-visibility-add-group' ) ) {
				addVisibilityGroup( block );
				return;
			}

			if ( event.target.closest( '.sf-visibility-add-rule' ) && group ) {
				addVisibilityRule( block, group );
				syncVisibilityNote( block );
				return;
			}

			if ( event.target.closest( '.sf-visibility-remove' ) ) {
				rule = event.target.closest( '[data-rule]' );

				if ( rule ) {
					rule.remove();
					syncVisibilityNote( block );
				}

				return;
			}

			if ( event.target.closest( '.sf-visibility-remove-group' ) && group ) {
				group.remove();
				refreshVisibilityJoins( block );
				syncVisibilityNote( block );
			}
		} );

		fieldVisibility.addEventListener( 'change', function ( event ) {
			var block = event.target.closest( '.sf-field-visibility' );
			var rule = event.target.closest( '[data-rule]' );
			var sourceType;

			if ( ! block ) {
				return;
			}

			if ( rule && event.target.classList.contains( 'sf-visibility-source' ) ) {
				sourceType = fieldTypeOf( event.target.value );
				refreshOperatorOptions( rule, sourceType );
				refreshValueControl( rule, sourceType );
			} else if ( rule && event.target.classList.contains( 'sf-visibility-operator' ) ) {
				syncValueHolderVisibility( rule );
			}

			syncVisibilityNote( block );
		} );

		fieldVisibility.addEventListener( 'input', function ( event ) {
			var block = event.target.closest( '.sf-field-visibility' );

			if ( block ) {
				syncVisibilityNote( block );
			}
		} );
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

		if ( fieldPreviews ) {
			var preview = fieldPreviews.querySelector( '.sf-field-preview[data-for="' + id + '"]' );

			if ( preview ) {
				preview.remove();
			}

			togglePreviewPanel( null !== fieldPreviews.querySelector( '.sf-field-preview' ) );
		}

		if ( fieldVisibility ) {
			var block = fieldVisibility.querySelector( '.sf-field-visibility[data-for="' + id + '"]' );

			if ( block ) {
				block.remove();
			}

			toggleVisibilityPanel( null !== fieldVisibility.querySelector( '.sf-field-visibility' ) );
		}

		if ( wasActive ) {
			var next = fieldList.querySelector( '.sf-field-item' );

			selectField( next ? next.getAttribute( 'data-for' ) : '' );
		}

		refreshVisibilitySources();
	}

	/**
	 * Rewrite every reference to a field id inside a cloned fragment
	 * (data-for, input names, sample ids/names, for/aria attributes).
	 *
	 * @param {Element} root  Cloned item or editor.
	 * @param {string}  oldId Original field id.
	 * @param {string}  newId Fresh field id.
	 */
	function reidentify( root, oldId, newId ) {
		var elements = [ root ].concat( Array.prototype.slice.call( root.querySelectorAll( '*' ) ) );

		elements.forEach( function ( element ) {
			Array.prototype.slice.call( element.attributes ).forEach( function ( attribute ) {
				if ( attribute.value.indexOf( oldId ) !== -1 ) {
					element.setAttribute( attribute.name, attribute.value.split( oldId ).join( newId ) );
				}
			} );
		} );
	}

	/**
	 * Duplicate one field: the same definition under a fresh id, inserted
	 * right after the original in the list and the editors column.
	 *
	 * @param {Element} item List item to duplicate.
	 */
	function duplicateField( item ) {
		var id = item.getAttribute( 'data-for' );
		var editor = fieldEditors.querySelector( '[data-for="' + id + '"]' );
		var newId = generateId();
		var newItem, newEditor, preview, newPreview;

		if ( ! editor ) {
			return;
		}

		newItem = item.cloneNode( true );
		newEditor = editor.cloneNode( true );

		reidentify( newItem, id, newId );
		reidentify( newEditor, id, newId );

		item.parentNode.insertBefore( newItem, item.nextSibling );
		editor.parentNode.insertBefore( newEditor, editor.nextSibling );

		preview = fieldPreviews ? fieldPreviews.querySelector( '.sf-field-preview[data-for="' + id + '"]' ) : null;

		if ( preview ) {
			newPreview = preview.cloneNode( true );
			reidentify( newPreview, id, newId );
			preview.parentNode.insertBefore( newPreview, preview.nextSibling );
		}

		if ( fieldVisibility ) {
			var block = fieldVisibility.querySelector( '.sf-field-visibility[data-for="' + id + '"]' );

			if ( block ) {
				var newBlock = block.cloneNode( true );

				reidentify( newBlock, id, newId );
				block.parentNode.insertBefore( newBlock, block.nextSibling );
			}
		}

		selectField( newId );
		newItem.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		refreshVisibilitySources();
	}

	/**
	 * Add a new field from the type template (list item + editor panel).
	 *
	 * @param {string} type Field type.
	 */
	function addField( type ) {
		var template = document.getElementById( 'sf-field-template-' + type );
		var id, wrapper, item, editor, preview, labelInput;

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

		if ( fieldPreviews ) {
			preview = wrapper.querySelector( '.sf-field-preview' );

			if ( preview ) {
				fieldPreviews.appendChild( preview );
				togglePreviewPanel( true );
			}
		}

		if ( fieldVisibility ) {
			var block = wrapper.querySelector( '.sf-field-visibility' );

			if ( block ) {
				fieldVisibility.appendChild( block );
				toggleVisibilityPanel( true );
			}
		}

		selectField( id );
		item.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		refreshVisibilitySources();

		labelInput = editor.querySelector( '.sf-input-label' );

		if ( labelInput ) {
			labelInput.focus();
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var duplicate = event.target.closest( 'button.sf-duplicate' );
		var remove = event.target.closest( 'button.sf-remove' );
		var item;

		if ( duplicate ) {
			item = duplicate.closest( '.sf-field-item' );

			if ( item ) {
				duplicateField( item );
			}

			return;
		}

		if ( remove ) {
			item = remove.closest( '.sf-field-item' );

			if ( item ) {
				removeField( item );
			}
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

			refreshVisibilitySources();
		} );

		// The list item shows the field name: keep it in sync with the label.
		// Every editor input also refreshes the live sample preview.
		fieldEditors.addEventListener( 'input', function ( event ) {
			var input = event.target;
			var editor, item, name;

			if ( ! input.name ) {
				return;
			}

			editor = input.closest( '.sf-field-editor' );

			if ( ! editor ) {
				return;
			}

			if ( input.name.indexOf( '[label]' ) !== -1 ) {
				item = fieldList.querySelector( '[data-for="' + editor.getAttribute( 'data-for' ) + '"]' );
				name = item ? item.querySelector( '.sf-field-item__name' ) : null;

				if ( name ) {
					name.textContent = input.value.trim() || fieldStrings.untitled || 'Untitled field';

					if ( fieldsTitle && item.classList.contains( 'sf-field-item--active' ) ) {
						fieldsTitle.textContent = name.textContent;
					}
				}

				refreshVisibilitySources();
				syncAllVisibilityNotes();
			} else if ( input.name.indexOf( '[choices]' ) !== -1 ) {
				refreshChoiceValueControls( editor.getAttribute( 'data-for' ) );
				syncAllVisibilityNotes();
			}

			syncSample( editor );
		} );

		// Required: asterisk next to the list name and in the sample label.
		fieldEditors.addEventListener( 'change', function ( event ) {
			var input = event.target;
			var editor, item, marker;

			if ( ! input.name || input.name.indexOf( '[required]' ) === -1 ) {
				return;
			}

			editor = input.closest( '.sf-field-editor' );

			if ( ! editor ) {
				return;
			}

			item = fieldList.querySelector( '[data-for="' + editor.getAttribute( 'data-for' ) + '"]' );
			marker = item ? item.querySelector( '.sf-field-item__required' ) : null;

			if ( marker ) {
				marker.hidden = ! input.checked;
			}

			syncSample( editor );
		} );

		// Select the first field (or show the empty state) on load.
		var firstItem = fieldList.querySelector( '.sf-field-item' );

		if ( firstItem ) {
			selectField( firstItem.getAttribute( 'data-for' ) );
		} else if ( fieldsEmpty ) {
			fieldsEmpty.hidden = false;
		}

		// Fill the visibility editors: server-rendered rules keep their
		// values, the source options follow the current list order.
		refreshVisibilitySources();
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
