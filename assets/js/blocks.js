/**
 * Ruvelo Inbox blocks in the editor. Both are rendered in PHP; the editor
 * shows that same output (ServerSideRender) with their settings in the
 * sidebar. Plain JavaScript with WordPress globals: no build step.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var ToggleControl = wp.components.ToggleControl;
	var RangeControl = wp.components.RangeControl;
	var TextControl = wp.components.TextControl;
	var Disabled = wp.components.Disabled;
	var ServerSideRender = wp.serverSideRender;

	function preview( name, attributes ) {
		return el( Disabled, null, el( ServerSideRender, {
			block: name,
			attributes: attributes,
			skipBlockSupportAttributes: true,
		} ) );
	}

	wp.blocks.registerBlockType( 'ruvelo/inbox', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			return el( 'div', useBlockProps(),
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Inbox', 'ruvelo-inbox' ) },
						el( ToggleControl, {
							label: __( 'Show the heading', 'ruvelo-inbox' ),
							checked: a.showHeading,
							onChange: function ( value ) { set( { showHeading: value } ); },
							__nextHasNoMarginBottom: true,
						} ),
						el( ToggleControl, {
							label: __( 'Show the Preferences tab', 'ruvelo-inbox' ),
							help: __( 'People choose which notifications they get, without visiting the admin.', 'ruvelo-inbox' ),
							checked: a.showPreferences,
							onChange: function ( value ) { set( { showPreferences: value } ); },
							__nextHasNoMarginBottom: true,
						} ),
						el( RangeControl, {
							label: __( 'Notifications per page', 'ruvelo-inbox' ),
							min: 5,
							max: 100,
							value: a.perPage,
							onChange: function ( value ) { set( { perPage: value } ); },
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} )
					)
				),
				preview( 'ruvelo/inbox', a )
			);
		},
		save: function () { return null; },
	} );

	wp.blocks.registerBlockType( 'ruvelo/inbox-bell', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			return el( 'div', useBlockProps(),
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Notification Bell', 'ruvelo-inbox' ) },
						el( TextControl, {
							label: __( 'Label', 'ruvelo-inbox' ),
							help: __( 'For screen readers and the dropdown’s heading. Defaults to “Notifications”.', 'ruvelo-inbox' ),
							value: a.label,
							onChange: function ( value ) { set( { label: value } ); },
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} )
					)
				),
				preview( 'ruvelo/inbox-bell', a )
			);
		},
		save: function () { return null; },
	} );
}( window.wp ) );
