/**
 * Divi Visual Builder preview for the "Relevant Form" module.
 *
 * The front end is rendered server-side (same path as the shortcode); this
 * component only draws the placeholder shown while editing the page.
 */
( function ( $ ) {
	'use strict';

	/**
	 * Escape a string for safe insertion into the preview markup.
	 *
	 * @param {string} value Raw value.
	 * @return {string} Escaped value.
	 */
	function escapeHtml( value ) {
		return String( value ).replace( /[&<>"']/g, function ( character ) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			}[ character ];
		} );
	}

	/**
	 * Draw the module placeholder in the Builder.
	 *
	 * @param {Object} props Module settings.
	 * @return {string} Preview HTML.
	 */
	function renderPreview( props ) {
		var data  = window.relevantFormsDivi || {};
		var forms = data.forms || {};
		var id    = props.form_id ? String( props.form_id ) : '';
		var label = id && forms[ id ] ? forms[ id ] : '';

		var message = label
			? 'Relevant Form: ' + escapeHtml( label )
			: 'Choose a form in the module settings panel.';

		return '<div class="relevant-forms-divi-preview" style="padding:24px;border:1px dashed #c3c4c7;border-radius:4px;text-align:center;color:#50575e;font-style:italic;">' + message + '</div>';
	}

	$( window ).on( 'et_builder_api_ready', function ( event, API ) {
		API.Modules.register( [
			{
				slug: 'relevant_form',
				name: 'Relevant Form',
				render: renderPreview
			}
		] );
	} );
} )( window.jQuery );
