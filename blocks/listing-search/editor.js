/**
 * Listing Search block: editor UI.
 *
 * Plain JavaScript using the WordPress globals, so the plugin needs no build
 * step. The preview is rendered by the same PHP code as the front end.
 */
( function ( blocks, blockEditor, components, element, i18n, ServerSideRender ) {
	const { createElement: el, Fragment } = element;
	const { InspectorControls, useBlockProps } = blockEditor;
	const { PanelBody, RangeControl, ToggleControl, TextControl, Disabled } = components;
	const { __ } = i18n;

	blocks.registerBlockType( 'reso-idx/listing-search', {
		edit( { attributes, setAttributes } ) {
			const blockProps = useBlockProps();
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Results', 'reso-idx' ) },
						el( RangeControl, {
							label: __( 'Listings per page', 'reso-idx' ),
							value: attributes.perPage,
							min: 3,
							max: 48,
							step: 3,
							onChange: ( perPage ) => setAttributes( { perPage } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show map', 'reso-idx' ),
							checked: attributes.showMap,
							onChange: ( showMap ) => setAttributes( { showMap } ),
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Fixed filters', 'reso-idx' ), initialOpen: false },
						el( TextControl, {
							label: __( 'City', 'reso-idx' ),
							help: __( 'Only show listings in this city, e.g. for a neighbourhood page.', 'reso-idx' ),
							value: attributes.city,
							onChange: ( city ) => setAttributes( { city } ),
						} ),
						el( TextControl, {
							label: __( 'Property type', 'reso-idx' ),
							help: __( 'Matches the type or subtype, e.g. Condominium.', 'reso-idx' ),
							value: attributes.propertyType,
							onChange: ( propertyType ) => setAttributes( { propertyType } ),
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( Disabled, null, el( ServerSideRender, { block: 'reso-idx/listing-search', attributes } ) )
				)
			);
		},
		save: () => null,
	} );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n, window.wp.serverSideRender );
