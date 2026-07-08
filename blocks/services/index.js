(function (wp) {
	var registerBlockType = wp.blocks.registerBlockType;
	var ServerSideRender = wp.serverSideRender;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var TextControl = wp.components.TextControl;
	var RangeControl = wp.components.RangeControl;
	var ToggleControl = wp.components.ToggleControl;
	var PanelBody = wp.components.PanelBody;
	var el = wp.element.createElement;

	registerBlockType('gx-zoho-bookings/services', {
		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el('div', null,
				el(InspectorControls, null,
					el(PanelBody, { title: 'Services Settings', initialOpen: true },
						el(TextControl, {
							label: 'Workspace ID (optional)',
							value: attributes.workspace,
							onChange: function (value) {
								setAttributes({ workspace: value });
							}
						}),
						el(RangeControl, {
							label: 'Columns',
							value: attributes.columns,
							onChange: function (value) {
								setAttributes({ columns: parseInt(value, 10) });
							},
							min: 1,
							max: 4,
							step: 1
						}),
						el(ToggleControl, {
							label: 'Show Description',
							checked: attributes.showDescription,
							onChange: function (value) {
								setAttributes({ showDescription: value });
							}
						}),
						el(ToggleControl, {
							label: 'Show Duration',
							checked: attributes.showDuration,
							onChange: function (value) {
								setAttributes({ showDuration: value });
							}
						}),
						el(TextControl, {
							label: 'Book button text',
							help: 'Label for free/no-payment services. Default: Book Now',
							placeholder: 'Book Now',
							value: attributes.bookLabel,
							onChange: function (value) {
								setAttributes({ bookLabel: value });
							}
						}),
						el(TextControl, {
							label: 'Book & Pay button text',
							help: 'Label for paid services with a Stripe link. Default: Book & Pay',
							placeholder: 'Book & Pay',
							value: attributes.payLabel,
							onChange: function (value) {
								setAttributes({ payLabel: value });
							}
						})
					)
				),
				el(ServerSideRender, {
					block: 'gx-zoho-bookings/services',
					attributes: attributes
				})
			);
		},
		save: function () {
			return null;
		}
	});
})(window.wp);
