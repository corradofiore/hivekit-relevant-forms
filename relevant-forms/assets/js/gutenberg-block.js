/**
 * "Relevant Form" block: pick a form and preview it. The front end is
 * server-rendered through the same code path as the shortcode, so the block
 * itself has no save output.
 *
 * Plain ES5 UI (no build step), mirroring how the plugin ships its other
 * admin scripts.
 */
( function ( blocks, element, components, serverSideRender ) {
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

	blocks.registerBlockType( 'relevant-forms/form-selector', {
		edit: function ( props ) {
			if ( ! hasForms ) {
				return el(
					'p',
					{ className: 'description' },
					forms[ '' ] || 'No forms yet — create one under Relevant Forms.'
				);
			}

			var children = [
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
} )( window.wp.blocks, window.wp.element, window.wp.components, window.wp.serverSideRender );
