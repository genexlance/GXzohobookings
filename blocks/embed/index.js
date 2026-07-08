(function (wp) {
	var registerBlockType = wp.blocks.registerBlockType;
	var ServerSideRender = wp.serverSideRender;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var TextControl = wp.components.TextControl;
	var RangeControl = wp.components.RangeControl;
	var PanelBody = wp.components.PanelBody;
	var el = wp.element.createElement;

	registerBlockType('gx-zoho-bookings/embed', {
		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el('div', null,
				el(InspectorControls, null,
					el(PanelBody, { title: 'Embed Settings', initialOpen: true },
						el(TextControl, {
							label: 'Booking Page URL',
							value: attributes.url,
							onChange: function (value) {
								setAttributes({ url: value });
							}
						}),
						el(RangeControl, {
							label: 'Height (px)',
							value: attributes.height,
							onChange: function (value) {
								setAttributes({ height: parseInt(value, 10) });
							},
							min: 200,
							max: 2000,
							step: 10
						})
					)
				),
				el(ServerSideRender, {
					block: 'gx-zoho-bookings/embed',
					attributes: attributes
				})
			);
		},
		save: function () {
			return null;
		}
	});
})(window.wp);
