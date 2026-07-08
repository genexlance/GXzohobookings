(function (wp) {
	var registerBlockType = wp.blocks.registerBlockType;
	var ServerSideRender = wp.serverSideRender;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var TextControl = wp.components.TextControl;
	var PanelBody = wp.components.PanelBody;
	var el = wp.element.createElement;

	registerBlockType('gx-zoho-bookings/service', {
		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el('div', null,
				el(InspectorControls, null,
					el(PanelBody, { title: 'Service Settings', initialOpen: true },
						el(TextControl, {
							label: 'Service ID',
							help: 'The Zoho Bookings service id to show. Landing pages set this automatically.',
							value: attributes.serviceId,
							onChange: function (value) {
								setAttributes({ serviceId: value });
							}
						})
					)
				),
				attributes.serviceId
					? el(ServerSideRender, {
						block: 'gx-zoho-bookings/service',
						attributes: attributes
					})
					: el('p', { style: { padding: '12px', border: '1px dashed #c3c4c7' } },
						'Enter a Service ID in the block settings to preview the service card.')
			);
		},
		save: function () {
			return null;
		}
	});
})(window.wp);
