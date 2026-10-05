/**
 * Relevant Forms front-end conditional visibility.
 *
 * Fields with visibility rules carry their normalized configuration in a
 * data-rf-visibility attribute (JSON) and the server already rendered the
 * initial state. This script re-evaluates on every input/change: rules in
 * a group are AND'ed, groups are OR'ed, and a full match flips the field's
 * default state. Hidden fields never block submit — their required flag is
 * removed while hidden and restored when the field is revealed.
 */
( function () {
	'use strict';

	var PREFIX = 'relevant_field_';

	/**
	 * Comparison-ready form of a value: decoded, trimmed, lowercased.
	 *
	 * @param {*} value Raw value.
	 * @return {string} Comparison-ready value.
	 */
	function flatten( value ) {
		var area = document.createElement( 'textarea' );

		area.innerHTML = String( null === value || undefined === value ? '' : value );

		return area.value.trim().toLowerCase();
	}

	/**
	 * Numeric reading of a value, or null when it is not a complete number.
	 *
	 * @param {string} value Comparison-ready value.
	 * @return {number|null} Number, or null.
	 */
	function asNumber( value ) {
		var cleaned = value.replace( /[^0-9.\-]/g, '' );
		var parsed = '' === cleaned ? NaN : Number( cleaned );

		return isNaN( parsed ) ? null : parsed;
	}

	/**
	 * Order two comparison-ready values: numerically when both are complete
	 * numbers, lexicographically otherwise.
	 *
	 * @param {string} left  Comparison-ready value.
	 * @param {string} right Comparison-ready value.
	 * @return {number} Negative, zero or positive.
	 */
	function compareOrder( left, right ) {
		var leftNumber = asNumber( left );
		var rightNumber = asNumber( right );

		if ( null !== leftNumber && null !== rightNumber ) {
			return leftNumber < rightNumber ? -1 : ( leftNumber > rightNumber ? 1 : 0 );
		}

		return left < right ? -1 : ( left > right ? 1 : 0 );
	}

	/**
	 * Apply an operator to the comparison-ready values of a field.
	 *
	 * @param {string}   operator Operator token.
	 * @param {string[]} values   Comparison-ready submitted values.
	 * @param {string}   right    Comparison-ready rule value.
	 * @return {boolean} Whether the rule passes.
	 */
	function compare( operator, values, right ) {
		switch ( operator ) {
			case 'is':
				return values.some( function ( value ) {
					return value === right;
				} );

			case 'is_not':
				return ! values.some( function ( value ) {
					return value === right;
				} );

			case 'contains':
				return values.some( function ( value ) {
					return value.indexOf( right ) !== -1;
				} );

			case 'not_contains':
				return ! values.some( function ( value ) {
					return value.indexOf( right ) !== -1;
				} );

			case 'starts_with':
				return values.some( function ( value ) {
					return 0 === value.indexOf( right );
				} );

			case 'ends_with':
				return values.some( function ( value ) {
					return value.slice( -right.length ) === right;
				} );

			case 'empty':
				return isEmpty( values );

			case 'not_empty':
				return ! isEmpty( values );

			case 'greater_than':
				return values.some( function ( value ) {
					return compareOrder( value, right ) > 0;
				} );

			case 'less_than':
				return values.some( function ( value ) {
					return compareOrder( value, right ) < 0;
				} );

			default:
				return false;
		}
	}

	/**
	 * All values (of possibly several) are empty.
	 *
	 * @param {string[]} values Comparison-ready values.
	 * @return {boolean} Whether every value is empty.
	 */
	function isEmpty( values ) {
		return values.every( function ( value ) {
			return '' === value;
		} );
	}

	/**
	 * Whether one rule matches the collected values.
	 *
	 * @param {Object} rule   Rule ({ field, operator, value }).
	 * @param {Object} values Field id => raw value(s).
	 * @return {boolean} Whether the rule passes.
	 */
	function ruleMatches( rule, values ) {
		// Rules whose source field is not on the form never match.
		if ( ! Object.prototype.hasOwnProperty.call( values, rule.field ) ) {
			return false;
		}

		var raw = values[ rule.field ];
		var list = Array.isArray( raw ) ? raw.map( flatten ) : [ flatten( raw ) ];

		return compare( rule.operator, list, flatten( rule.value ) );
	}

	/**
	 * Whether a field is visible under its configuration.
	 *
	 * @param {Object} config Normalized visibility configuration.
	 * @param {Object} values Field id => raw value(s).
	 * @return {boolean} Whether the field is visible.
	 */
	function fieldVisible( config, values ) {
		var matched = false;

		config.groups.forEach( function ( group ) {
			if ( group.every( function ( rule ) {
				return ruleMatches( rule, values );
			} ) ) {
				matched = true;
			}
		} );

		return 'hidden' === config.default ? matched : ! matched;
	}

	/**
	 * Collect the current values of every control of the form.
	 *
	 * @param {Element} form Form element.
	 * @return {Object} Field id => raw value(s).
	 */
	function collectValues( form ) {
		var values = {};

		form.querySelectorAll( 'input[name], select[name], textarea[name]' ).forEach( function ( control ) {
			var name = control.getAttribute( 'name' ) || '';

			if ( 0 !== name.indexOf( PREFIX ) ) {
				return;
			}

			var id   = name.slice( PREFIX.length ).replace( /\[\]$/, '' );
			var type = ( control.getAttribute( 'type' ) || '' ).toLowerCase();

			if ( 'checkbox' === type || 'radio' === type ) {
				if ( ! control.checked ) {
					if ( ! Object.prototype.hasOwnProperty.call( values, id ) ) {
						values[ id ] = '';
					}

					return;
				}

				if ( Array.isArray( values[ id ] ) ) {
					values[ id ] = values[ id ].concat( [ control.value ] );
				} else {
					values[ id ] = values[ id ] ? [ values[ id ], control.value ] : [ control.value ];
				}

				return;
			}

			values[ id ] = control.value;
		} );

		return values;
	}

	/**
	 * Keep the required state in step with the visibility.
	 *
	 * @param {Element} wrapper Field wrapper.
	 * @param {boolean} visible Whether the field is visible.
	 */
	function syncRequired( wrapper, visible ) {
		var required = '1' === wrapper.getAttribute( 'data-rf-required' );
		var fieldset = wrapper.querySelector( 'fieldset' );

		if ( fieldset ) {
			if ( visible && required ) {
				fieldset.setAttribute( 'aria-required', 'true' );
			} else {
				fieldset.removeAttribute( 'aria-required' );
			}

			// Radio groups mirror the native attribute the field renderer
			// ships, so a revealed required group blocks submission like a
			// dropdown does. Other groups (checkboxes, payments) stay
			// aria-only, matching their renderers.
			if ( wrapper.classList.contains( 'relevant-forms-field--radio' ) ) {
				fieldset.querySelectorAll( 'input[type="radio"]' ).forEach( function ( control ) {
					if ( visible && required ) {
						control.setAttribute( 'required', 'required' );
					} else {
						control.removeAttribute( 'required' );
					}
				} );
			}

			return;
		}

		wrapper.querySelectorAll( 'input, select, textarea' ).forEach( function ( control ) {
			var type = ( control.getAttribute( 'type' ) || '' ).toLowerCase();

			if ( 'hidden' === type || 'submit' === type || 'button' === type ) {
				return;
			}

			if ( visible && required ) {
				control.setAttribute( 'required', 'required' );
			} else {
				control.removeAttribute( 'required' );
			}
		} );
	}

	/**
	 * Re-evaluate every conditional field of one form.
	 *
	 * @param {Element} form Form element.
	 */
	function apply( form ) {
		var values = collectValues( form );

		form.querySelectorAll( '.relevant-forms-field[data-rf-visibility]' ).forEach( function ( wrapper ) {
			var config;

			try {
				config = JSON.parse( wrapper.getAttribute( 'data-rf-visibility' ) );
			} catch ( error ) {
				return;
			}

			var visible = fieldVisible( config, values );

			wrapper.hidden = ! visible;
			syncRequired( wrapper, visible );
		} );

		// Let sibling behaviours (for example multi-page navigation) react to
		// the new visibility state.
		form.dispatchEvent( new CustomEvent( 'relevant-forms:visibility' ) );
	}

	/**
	 * Bind every form under a root.
	 *
	 * @param {Element} root Scope to search (document when omitted).
	 */
	function boot( root ) {
		( root || document ).querySelectorAll( 'form.relevant-forms' ).forEach( function ( form ) {
			if ( '1' === form.getAttribute( 'data-rf-conditional-ready' ) ) {
				return;
			}

			form.setAttribute( 'data-rf-conditional-ready', '1' );

			form.addEventListener( 'input', function () {
				apply( form );
			} );

			form.addEventListener( 'change', function () {
				apply( form );
			} );

			// Normalize the server-rendered initial state.
			apply( form );
		} );
	}

	boot( document );

	// Allow re-runs on dynamically injected markup (Divi previews).
	( window.relevantFormsFrontend = window.relevantFormsFrontend || [] ).push( boot );
}() );
