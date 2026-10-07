/**
 * "Relevant Form" block: pick a form, style its buttons, and preview it.
 * The front end is server-rendered through the same code path as the
 * shortcode, so the block itself has no save output.
 *
 * Plain ES5 UI (no build step), mirroring how the plugin ships its other
 * admin scripts.
 */
( function ( blocks, element, components, blockEditor, serverSideRender ) {
	'use strict';

	var el       = element.createElement;
	var settings = window.relevantFormsBlock || {};
	var forms    = settings.forms || {};

	var options = [
		{ value: 0, label: settings.selectLabel || 'Select a form' }
	];

	Object.keys( forms ).forEach( function ( id ) {
		if ( '' !== id ) {
			options.push( { value: parseInt( id, 10 ), label: forms[ id ] } );
		}
	} );

	var hasForms = Object.keys( forms ).some( function ( id ) {
		return '' !== id;
	} );

	var ServerSideRender = ( serverSideRender && ( serverSideRender.default || serverSideRender ) )
		|| ( components && components.ServerSideRender );

	var paddingUnits = [
		{ value: 'px', label: 'px' },
		{ value: 'em', label: 'em' },
		{ value: 'rem', label: 'rem' },
		{ value: '%', label: '%' }
	];

	var weightOptions = [
		{ value: '', label: 'Default' },
		{ value: '400', label: 'Normal (400)' },
		{ value: '500', label: 'Medium (500)' },
		{ value: '600', label: 'Semibold (600)' },
		{ value: '700', label: 'Bold (700)' }
	];

	blocks.registerBlockType( 'relevant-forms/form-selector', {
		edit: function ( props ) {
			if ( ! hasForms ) {
				return el(
					'p',
					{ className: 'description' },
					forms[ '' ] || 'No forms yet — create one under Relevant Forms.'
				);
			}

			// The button controls override the theme's button styling for
			// this embed only; when cleared, the buttons keep the theme's
			// own styling. The ServerSideRender preview below re-renders
			// the moment a value changes.
			var buttonPanel = el(
				components.PanelBody,
				{ title: 'Button styles', initialOpen: false },
				el( components.PanelColorSettings, {
					key: 'colors',
					title: 'Colors',
					colors: [],
					colorSettings: [
						{
							value: props.attributes.buttonBgColor,
							onChange: function ( value ) {
								props.setAttributes( { buttonBgColor: value || '' } );
							},
							label: 'Background'
						},
						{
							value: props.attributes.buttonTextColor,
							onChange: function ( value ) {
								props.setAttributes( { buttonTextColor: value || '' } );
							},
							label: 'Text color'
						}
					]
				} ),
				el( components.UnitControl, {
					key: 'radius',
					label: 'Rounded corners',
					value: props.attributes.buttonRadius,
					onChange: function ( value ) {
						props.setAttributes( { buttonRadius: value || '' } );
					}
				} ),
				el( components.BoxControl, {
					key: 'padding',
					label: 'Padding',
					values: Object.assign( {}, props.attributes.buttonPadding ),
					units: paddingUnits,
					onChange: function ( value ) {
						props.setAttributes( { buttonPadding: value } );
					}
				} )
			);

			// One panel per text group: the field labels, the captions under
			// them and the option labels beside radio/checkbox boxes are
			// configured independently. Clearing a control keeps the theme's
			// own styling.
			var textGroups = [
				{ key: 'label', title: 'Field labels' },
				{ key: 'caption', title: 'Field captions' },
				{ key: 'option', title: 'Option labels' }
			];

			var textPanels = textGroups.map( function ( group ) {
				var colorAttribute = group.key + 'Color';
				var sizeAttribute = group.key + 'Size';
				var weightAttribute = group.key + 'Weight';

				return el(
					components.PanelBody,
					{ key: 'text-' + group.key, title: group.title, initialOpen: false },
					el( components.PanelColorSettings, {
						key: 'text-color',
						title: 'Text color',
						colors: [],
						colorSettings: [
							{
								value: props.attributes[ colorAttribute ],
								onChange: function ( value ) {
									var next = {};
									next[ colorAttribute ] = value || '';
									props.setAttributes( next );
								},
								label: group.title
							}
						]
					} ),
					el( components.UnitControl, {
						key: 'text-size',
						label: 'Font size',
						value: props.attributes[ sizeAttribute ],
						onChange: function ( value ) {
							var next = {};
							next[ sizeAttribute ] = value || '';
							props.setAttributes( next );
						}
					} ),
					el( components.SelectControl, {
						key: 'text-weight',
						label: 'Font weight',
						value: props.attributes[ weightAttribute ],
						options: weightOptions,
						onChange: function ( value ) {
							var next = {};
							next[ weightAttribute ] = value || '';
							props.setAttributes( next );
						}
					} )
				);
			} );

			var inspector = el(
				blockEditor.InspectorControls,
				{ key: 'relevant-form-styles' },
				textPanels,
				buttonPanel
			);

			var children = [
				inspector,
				el( components.SelectControl, {
					key: 'selector',
					label: 'Form',
					value: props.attributes.formId,
					options: options,
					onChange: function ( value ) {
						props.setAttributes( { formId: parseInt( value, 10 ) || 0 } );
					}
				} )
			];

			if ( props.attributes.formId && ServerSideRender ) {
				children.push(
					el( ServerSideRender, {
						key: 'preview',
						block: 'relevant-forms/form-selector',
						attributes: props.attributes
					} )
				);
			}

			return el( 'div', {}, children );
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.serverSideRender );
