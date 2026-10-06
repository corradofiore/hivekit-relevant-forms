/**
 * Divi 5 Visual Builder component for the "Relevant Form" module.
 *
 * Divi 5's builder renders every module through its own module library, so
 * a module registered only through the legacy Divi 4 API never gets a
 * preview component and its placeholder stays on the loading state. This
 * script registers the module with the Divi 5 runtime: the settings panel
 * (a form picker), the live preview (the form's real front-end markup,
 * fetched from admin-ajax) and the module styles renderer. It runs in the
 * builder's app window, enqueued by ModernModule::enqueueVisualBuilderAssets().
 */
( function () {
	'use strict';

	// Keep in sync with ModernModule::MODULE_NAME and module.json (also the
	// icon name, which module.json lists as moduleIcon).
	var MODULE_NAME = 'relevant-forms/divi-form-selector';

	var vendor = window.vendor || {};
	var vendorWp = vendor.wp || {};
	var hooks = vendorWp.hooks || ( window.wp && window.wp.hooks );
	var element = vendorWp.element || ( window.wp && window.wp.element );
	var divi = window.divi || {};

	if (
		! window.React ||
		! hooks ||
		! element ||
		! divi.module ||
		! divi.moduleLibrary ||
		! divi.fieldLibrary ||
		! divi.moduleUtils
	) {
		return;
	}

	var React = window.React;
	var RawHTML = element.RawHTML;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var useRef = element.useRef;

	// Localized data registered as the package's data_app_window. Divi
	// derives the global name from the package name ("relevant-forms-divi"
	// -> RelevantFormsDiviData); both spellings are checked so a change in
	// that derivation cannot break the module silently.
	var data = window.RelevantFormsDiviData || window.relevantFormsDiviData || {};

	/**
	 * Copy own enumerable properties onto a target object.
	 *
	 * @param {Object} target Target object.
	 * @param {...Object} sources Objects to copy from.
	 * @return {Object} Target object.
	 */
	function extend( target ) {
		var sources = Array.prototype.slice.call( arguments, 1 );

		sources.forEach( function ( source ) {
			Object.keys( source || {} ).forEach( function ( key ) {
				target[ key ] = source[ key ];
			} );
		} );

		return target;
	}

	/**
	 * Inject the preview's small stylesheet once into the app window.
	 */
	function ensurePreviewStyles() {
		if ( document.getElementById( 'relevant-forms-divi-styles' ) ) {
			return;
		}

		var style = document.createElement( 'style' );

		style.id = 'relevant-forms-divi-styles';
		style.textContent =
			'.relevant-forms-divi-loading{width:26px;height:26px;margin:22px auto;border:3px solid #dcdcde;border-top-color:#2271b1;border-radius:50%;animation:relevant-forms-divi-spin .9s linear infinite}' +
			'@keyframes relevant-forms-divi-spin{to{transform:rotate(360deg)}}' +
			'.relevant-forms-divi-empty,.relevant-forms-divi-notice{margin:16px;padding:14px 16px;border:1px dashed #c3c4c7;border-radius:4px;text-align:center;color:#50575e}' +
			'.relevant-forms-divi-empty a{display:inline-block;margin-top:6px}';

		( document.head || document.documentElement ).appendChild( style );
	}

	/**
	 * Re-run the front-end behaviours inside a freshly injected preview.
	 * Every script of assets/js/frontend-*.js registers its boot function on
	 * window.relevantFormsFrontend, and each boot is idempotent per element,
	 * so re-running them is always safe.
	 *
	 * @param {Element} root Element containing the injected form.
	 */
	function initFrontend( root ) {
		var boots = window.relevantFormsFrontend || [];

		Array.prototype.forEach.call( boots, function ( boot ) {
			if ( typeof boot === 'function' ) {
				boot( root );
			}
		} );
	}

	/**
	 * Resolve one module attribute to a plain string ('' when unset).
	 *
	 * @param {Object} attr Raw mode-wrapped attribute.
	 * @return {string} Resolved value.
	 */
	function modeValue( attr ) {
		var resolved = divi.moduleUtils.getAttrByMode( attr );

		return ( 'string' === typeof resolved || 'number' === typeof resolved ) ? String( resolved ) : '';
	}

	/**
	 * Fetch the form's front-end markup for the preview. Re-fetches when the
	 * form or any button style value changes, so the canvas always shows
	 * what the front end would render.
	 *
	 * @param {string} formId  Selected form ID.
	 * @param {Object} buttons Button style values (background, text, radius, padding).
	 * @return {Object} { isLoading, html, failed }.
	 */
	function usePreviewFetch( formId, buttons ) {
		var state = useState( { isLoading: false, html: '', failed: false } );
		var current = state[ 0 ];
		var setState = state[ 1 ];
		var controller = useRef( null );
		var buttonsKey = JSON.stringify( buttons );

		useEffect( function () {
			if ( ! formId ) {
				setState( { isLoading: false, html: '', failed: false } );
				return undefined;
			}

			if ( controller.current ) {
				controller.current.abort();
			}

			var active = new AbortController();

			controller.current = active;
			setState( { isLoading: true, html: '', failed: false } );

			var body = new URLSearchParams();

			body.append( 'action', 'relevant_forms_divi_preview' );
			body.append( 'nonce', data.nonce );
			body.append( 'form_id', formId );
			body.append( 'buttons', buttonsKey );

			window
				.fetch( data.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body,
					signal: active.signal
				} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					setState( {
						isLoading: false,
						html: result && result.success && typeof result.data === 'string' ? result.data : '',
						failed: ! ( result && result.success )
					} );
				} )
				.catch( function () {
					if ( ! active.signal.aborted ) {
						setState( { isLoading: false, html: '', failed: true } );
					}
				} );

			return function () {
				active.abort();
			};
		}, [ formId, buttonsKey ] );

		return current;
	}

	/**
	 * The module preview: the form's front-end markup, a spinner while it
	 * loads, and explanatory text when there is nothing to show.
	 *
	 * @param {Object} props Module props (attrs, elements, ...).
	 * @return {Object} Preview element.
	 */
	function Preview( props ) {
		var formId = divi.moduleUtils.getAttrByMode( props.attrs.formId );
		var hasForms = Object.keys( data.forms || {} ).length > 0;
		var buttonValues = {
			background: modeValue( props.attrs.buttonBg ),
			text: modeValue( props.attrs.buttonText ),
			radius: modeValue( props.attrs.buttonRadius ),
			padding: modeValue( props.attrs.buttonPadding )
		};
		var state = usePreviewFetch( formId, buttonValues );
		var previewRef = useRef( null );

		// Runs after every render: binds the form's behaviours once the
		// preview markup is in the DOM (and re-binds when React replaces it).
		useEffect( function () {
			if ( state.html && previewRef.current ) {
				initFrontend( previewRef.current );
			}
		} );

		if ( ! hasForms ) {
			return React.createElement(
				'div',
				{ className: 'relevant-forms-divi-empty' },
				data.noForms,
				data.noFormsUrl
					? React.createElement(
							'a',
							{ href: data.noFormsUrl, target: '_blank', rel: 'noopener noreferrer' },
							data.openAdmin
					  )
					: null
			);
		}

		if ( ! formId ) {
			return React.createElement( 'div', { className: 'relevant-forms-divi-empty' }, data.selectForm );
		}

		if ( state.isLoading ) {
			return React.createElement( 'div', {
				className: 'relevant-forms-divi-loading',
				role: 'status',
				'aria-label': data.loading
			} );
		}

		if ( state.failed ) {
			return React.createElement( 'div', { className: 'relevant-forms-divi-notice' }, data.loadFailed );
		}

		if ( ! state.html ) {
			return React.createElement( 'div', { className: 'relevant-forms-divi-notice' }, data.formMissing );
		}

		return React.createElement(
			'div',
			{
				ref: previewRef,
				className: 'relevant-forms-divi-preview',
				// Keep submissions inside the builder: a real POST from the
				// canvas would navigate the editor frame, not the live page.
				onSubmitCapture: function ( event ) {
					event.preventDefault();
				}
			},
			React.createElement( RawHTML, null, state.html )
		);
	}

	/**
	 * The module as the builder draws it: the standard module container
	 * with the preview inside et_pb_module_inner.
	 *
	 * @param {Object} props Module props.
	 * @return {Object} Module element.
	 */
	function Edit( props ) {
		return React.createElement(
			divi.module.ModuleContainer,
			{
				attrs: props.attrs,
				elements: props.elements,
				id: props.id,
				moduleClassName: 'relevant-forms-divi-module',
				name: props.name,
				stylesComponent: ModuleStyles
			},
			React.createElement( 'div', { className: 'et_pb_module_inner' }, React.createElement( Preview, props ) )
		);
	}

	/**
	 * Module styles renderer: the standard module style components plus the
	 * custom CSS fields from module.json.
	 *
	 * @param {Object} props Style props (mode, elements, orderClass, attrs, ...).
	 * @return {Object} Styles element.
	 */
	function ModuleStyles( props ) {
		var moduleMetadata = props.elements && props.elements.moduleMetadata;

		return React.createElement(
			divi.module.StyleContainer,
			{ mode: props.mode, state: props.state, noStyleTag: props.noStyleTag },
			props.elements.style( { attrName: 'module' } ),
			React.createElement( divi.module.CssStyle, {
				selector: props.orderClass,
				attr: props.attrs.css,
				orderClass: props.orderClass,
				cssFields: moduleMetadata ? moduleMetadata.customCssFields : null
			} )
		);
	}

	/**
	 * Settings panel content: a "Form Settings" group with the form picker
	 * and a "Button styles" group with the button overrides.
	 *
	 * @param {Object} props Settings props from the module library.
	 * @return {Object} Settings element.
	 */
	function Settings( props ) {
		var labels = data.buttonSettings || {};

		return React.createElement(
			React.Fragment,
			null,
			React.createElement(
				divi.module.GroupContainer,
				extend( {}, props, { id: 'formSettings', title: data.settingsGroup.label } ),
				React.createElement(
					divi.module.FieldContainer,
					extend( {}, props, { attrName: 'formId', label: data.formId.label } ),
					React.createElement( divi.fieldLibrary.Select, {
						options: data.forms || {},
						emptyLabel: data.formId.placeholder
					} )
				)
			),
			React.createElement(
				divi.module.GroupContainer,
				extend( {}, props, { id: 'buttonSettings', title: labels.label } ),
				React.createElement(
					divi.module.FieldContainer,
					extend( {}, props, { attrName: 'buttonBg', label: labels.background } ),
					React.createElement( divi.fieldLibrary.ColorPicker, {} )
				),
				React.createElement(
					divi.module.FieldContainer,
					extend( {}, props, { attrName: 'buttonText', label: labels.text } ),
					React.createElement( divi.fieldLibrary.ColorPicker, {} )
				),
				React.createElement(
					divi.module.FieldContainer,
					extend( {}, props, { attrName: 'buttonRadius', label: labels.radius } ),
					React.createElement( divi.fieldLibrary.Text, { placeholder: labels.radiusPlaceholder } )
				),
				React.createElement(
					divi.module.FieldContainer,
					extend( {}, props, { attrName: 'buttonPadding', label: labels.padding } ),
					React.createElement( divi.fieldLibrary.Text, { placeholder: labels.paddingPlaceholder } )
				)
			)
		);
	}

	/**
	 * The module metadata shared with module.json (name, attributes and the
	 * module library category).
	 *
	 * Design groups like Background, Border, Box Shadow, Filters and Text
	 * are redirected to the form's input fields through `styleProps`, so the
	 * native Divi controls style the fields instead of the module wrapper.
	 */
	var fieldsSelector = '{{selector}} .relevant-forms-field :is(input, select, textarea):not([type=checkbox]):not([type=radio])';
	var fieldsSelectorPlaceholder = fieldsSelector + '::placeholder';

	var metadata = {
		name: MODULE_NAME,
		d4Shortcode: 'relevant_form',
		title: 'Relevant Form',
		titles: 'Forms',
		moduleIcon: MODULE_NAME,
		category: 'module',
		attributes: {
			module: {
				type: 'object',
				selector: '{{selector}}',
				default: {},
				defaultPrintedStyle: {},
				styleProps: {
					selectors: {
						desktop: {
							value: fieldsSelector + ', ' + fieldsSelectorPlaceholder + ', ' + fieldsSelector + '::-webkit-input-placeholder'
						}
					},
					background: { selector: fieldsSelector },
					border: { selector: fieldsSelector },
					boxShadow: { selector: fieldsSelector, important: true },
					filters: { selector: fieldsSelector },
					font: { selector: fieldsSelector + ', ' + fieldsSelectorPlaceholder },
					animation: { selector: '{{selector}}' },
					sizing: { selector: '{{selector}}' },
					spacing: {
						selector: '{{selector}}',
						propertySelectors: {
							desktop: {
								value: {
									padding: fieldsSelector
								}
							}
						}
					},
					transform: { selector: '{{selector}}' },
					transition: { selector: '{{selector}}' },
					position: { selector: '{{selector}}' }
				},
				settings: {
					advanced: { htmlAttributes: {} },
					decoration: {
						animation: {},
						background: {},
						border: {},
						boxShadow: {},
						filters: {},
						font: {},
						sizing: {},
						spacing: {},
						transform: {},
						conditions: {},
						disabledOn: {},
						position: {},
						scroll: {},
						transition: {}
					}
				}
			},
			formId:        { type: 'string', default: '0' },
			buttonBg:      { type: 'string', default: '' },
			buttonText:    { type: 'string', default: '' },
			buttonRadius:  { type: 'string', default: '' },
			buttonPadding: { type: 'string', default: '' }
		},
		customCssFields: {},
		settings: { design: 'auto', advanced: 'auto' }
	};

	/**
	 * The module library icon (simple form glyph).
	 */
	var icon = {
		name: MODULE_NAME,
		viewBox: '0 0 16 16',
		component: function () {
			return React.createElement( 'path', {
				d: 'M2 1.5h12A1.5 1.5 0 0 1 15.5 3v10a1.5 1.5 0 0 1-1.5 1.5H2A1.5 1.5 0 0 1 .5 13V3A1.5 1.5 0 0 1 2 1.5Zm0 1.5a.5.5 0 0 0-.5.5v9a.5.5 0 0 0 .5.5h12a.5.5 0 0 0 .5-.5V3a.5.5 0 0 0-.5-.5H2Zm1.5 2h9v1.5h-9V5Zm0 3h9v1.5h-9V8Zm0 3h5.5v1.5H3.5V11Z'
			} );
		}
	};

	ensurePreviewStyles();

	hooks.addFilter( 'divi.iconLibrary.icon.map', 'relevant-forms', function ( icons ) {
		var next = extend( {}, icons );

		next[ icon.name ] = icon;

		return next;
	} );

	hooks.addAction( 'divi.moduleLibrary.registerModuleLibraryStore.after', 'relevant-forms', function () {
		divi.moduleLibrary.registerModule( metadata, {
			metadata: metadata,
			renderers: {
				edit: Edit,
				styles: ModuleStyles
			},
			settings: {
				content: Settings
			}
		} );
	} );
} )();
