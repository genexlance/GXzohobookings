(function (wp) {
    var registerBlockType = wp.blocks.registerBlockType;
    var ServerSideRender = wp.serverSideRender;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var TextControl = wp.components.TextControl;
    var ToggleControl = wp.components.ToggleControl;
    var PanelBody = wp.components.PanelBody;
    var el = wp.element.createElement;

    registerBlockType('gx-zoho-bookings/book', {
        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;

            return [
                el(InspectorControls, null,
                    el(PanelBody, { title: 'Booking Form Settings', initialOpen: true },
                        el(TextControl, {
                            label: 'Preselect service ID (optional)',
                            help: 'Leave empty to let the visitor choose.',
                            value: attributes.serviceId,
                            onChange: function (value) {
                                setAttributes({ serviceId: value });
                            }
                        }),
                        el(ToggleControl, {
                            label: 'Collect phone number',
                            checked: attributes.showPhone,
                            onChange: function (value) {
                                setAttributes({ showPhone: value });
                            }
                        }),
                        attributes.showPhone ? el(ToggleControl, {
                            label: 'Phone is required',
                            checked: attributes.requirePhone,
                            onChange: function (value) {
                                setAttributes({ requirePhone: value });
                            }
                        }) : null,
                        el(ToggleControl, {
                            label: 'Collect booking notes',
                            checked: attributes.showNotes,
                            onChange: function (value) {
                                setAttributes({ showNotes: value });
                            }
                        })
                    )
                ),
                el(ServerSideRender, {
                    block: 'gx-zoho-bookings/book',
                    attributes: attributes
                })
            ];
        },
        save: function () {
            return null;
        }
    });
})(window.wp);
