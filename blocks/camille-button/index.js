/**
 * Editor side of the Camille button block. Plain script, no build step: it uses the wp globals.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'camille-personal-shopper/button', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var fallback = attributes.mode === 'chat'
				? __( 'Ask Camille', 'camille-personal-shopper' )
				: __( 'Try with Camille', 'camille-personal-shopper' );
			return el(
				element.Fragment,
				null,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Camille button', 'camille-personal-shopper' ) },
						el( components.TextControl, {
							label: __( 'Text', 'camille-personal-shopper' ),
							help: __( 'Leave empty for the default text in the language of the page.', 'camille-personal-shopper' ),
							value: attributes.label,
							onChange: function ( value ) {
								props.setAttributes( { label: value } );
							},
						} ),
						el( components.SelectControl, {
							label: __( 'What it opens', 'camille-personal-shopper' ),
							value: attributes.mode,
							options: [
								{ value: 'tryon', label: __( 'Camille with this product (on product pages)', 'camille-personal-shopper' ) },
								{ value: 'chat', label: __( 'The chat with Camille', 'camille-personal-shopper' ) },
							],
							onChange: function ( value ) {
								props.setAttributes( { mode: value } );
							},
						} )
					)
				),
				el(
					'div',
					blockEditor.useBlockProps(),
					el( 'span', { className: 'wp-element-button' }, attributes.label || fallback )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
