<?php
/**
 * Per-service custom booking fields (paid: additional_fields).
 *
 * Zoho's paid plans let a service collect extra information at booking time.
 * The Zoho API accepts these as an `additional_fields` JSON blob but does not
 * expose a stable endpoint to read a service's field definitions, so the
 * definitions are stored site-side (mirroring GX_ZB_Staff_Meta) and their
 * collected values are forwarded to Zoho on booking.
 *
 * @package GX_Zoho_Bookings
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Custom-field definition store + form helpers.
 */
final class GX_ZB_Fields {

	/**
	 * Singleton instance.
	 *
	 * @var GX_ZB_Fields|null
	 */
	private static $instance = null;

	/**
	 * Option key holding the service_id => field-defs map.
	 */
	const OPTION = 'gx_zb_service_fields';

	/**
	 * Allowed field input types.
	 *
	 * @var string[]
	 */
	private static $types = array( 'text', 'textarea', 'select', 'checkbox' );

	/**
	 * Get the shared instance.
	 *
	 * @since 2.0.0
	 * @return GX_ZB_Fields
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Full map of service_id => array of field definitions.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	public function all() {
		$map = get_option( self::OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Field definitions for one service.
	 *
	 * @since 2.0.0
	 * @param string $service_id Service ID.
	 * @return array[] List of field-def arrays (key,label,type,required,options).
	 */
	public function get( $service_id ) {
		$map = $this->all();
		return isset( $map[ $service_id ] ) && is_array( $map[ $service_id ] ) ? $map[ $service_id ] : array();
	}

	/**
	 * Persist sanitized field definitions for one service.
	 *
	 * @since 2.0.0
	 * @param string $service_id Service ID.
	 * @param array  $defs       Raw field definitions.
	 * @return void
	 */
	public function save( $service_id, array $defs ) {
		$service_id = sanitize_text_field( $service_id );
		$clean      = array();

		foreach ( $defs as $def ) {
			if ( empty( $def['label'] ) ) {
				continue;
			}
			$label = sanitize_text_field( $def['label'] );
			$key   = ! empty( $def['key'] ) ? sanitize_key( $def['key'] ) : sanitize_key( $label );
			if ( '' === $key ) {
				continue;
			}
			$type = isset( $def['type'] ) && in_array( $def['type'], self::$types, true ) ? $def['type'] : 'text';

			$options = array();
			if ( 'select' === $type && ! empty( $def['options'] ) ) {
				$raw = is_array( $def['options'] ) ? $def['options'] : explode( ',', (string) $def['options'] );
				foreach ( $raw as $opt ) {
					$opt = sanitize_text_field( trim( $opt ) );
					if ( '' !== $opt ) {
						$options[] = $opt;
					}
				}
			}

			$clean[] = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $def['required'] ),
				'options'  => $options,
			);
		}

		$map = $this->all();
		if ( empty( $clean ) ) {
			unset( $map[ $service_id ] );
		} else {
			$map[ $service_id ] = $clean;
		}
		update_option( self::OPTION, $map );
	}

	/**
	 * Render the custom-field inputs for a service's booking form.
	 *
	 * Inputs are namespaced under gx_zb_cf[key] so collect() can read them.
	 *
	 * @since 2.0.0
	 * @param string $service_id Service ID.
	 * @return string HTML, empty when the service has no custom fields.
	 */
	public function render_inputs( $service_id ) {
		$defs = $this->get( $service_id );
		if ( empty( $defs ) ) {
			return '';
		}

		$html = '<div class="gx-zb-custom-fields">';
		foreach ( $defs as $def ) {
			$name  = 'gx_zb_cf[' . esc_attr( $def['key'] ) . ']';
			$id    = 'gx-zb-cf-' . esc_attr( $def['key'] );
			$req   = ! empty( $def['required'] );
			$label = esc_html( $def['label'] ) . ( $req ? ' <span class="gx-zb-required">*</span>' : '' );

			$html .= '<p class="gx-zb-field gx-zb-field-' . esc_attr( $def['type'] ) . '">';
			if ( 'checkbox' === $def['type'] ) {
				$html .= '<label for="' . $id . '"><input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . ( $req ? ' required' : '' ) . '> ' . $label . '</label>';
			} else {
				$html .= '<label for="' . $id . '">' . $label . '</label>';
				switch ( $def['type'] ) {
					case 'textarea':
						$html .= '<textarea id="' . $id . '" name="' . $name . '"' . ( $req ? ' required' : '' ) . '></textarea>';
						break;
					case 'select':
						$html .= '<select id="' . $id . '" name="' . $name . '"' . ( $req ? ' required' : '' ) . '>';
						$html .= '<option value="">' . esc_html__( 'Select…', 'gx-zoho-bookings' ) . '</option>';
						foreach ( $def['options'] as $opt ) {
							$html .= '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
						}
						$html .= '</select>';
						break;
					default:
						$html .= '<input type="text" id="' . $id . '" name="' . $name . '"' . ( $req ? ' required' : '' ) . '>';
				}
			}
			$html .= '</p>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Read + validate submitted custom-field values for a service.
	 *
	 * @since 2.0.0
	 * @param string $service_id Service ID.
	 * @param array  $source     Raw request array (e.g. $_POST['gx_zb_cf']).
	 * @return array {
	 *     @type array    $values Field label => sanitized value (for Zoho additional_fields).
	 *     @type string[] $errors Validation error messages.
	 * }
	 */
	public function collect( $service_id, array $source ) {
		$defs   = $this->get( $service_id );
		$values = array();
		$errors = array();

		foreach ( $defs as $def ) {
			$raw = isset( $source[ $def['key'] ] ) ? wp_unslash( $source[ $def['key'] ] ) : '';

			if ( 'checkbox' === $def['type'] ) {
				$val = ! empty( $raw ) ? '1' : '';
			} elseif ( 'textarea' === $def['type'] ) {
				$val = sanitize_textarea_field( $raw );
			} else {
				$val = sanitize_text_field( $raw );
			}

			// Reject select values outside the defined option set.
			if ( 'select' === $def['type'] && '' !== $val && ! in_array( $val, $def['options'], true ) ) {
				$val = '';
			}

			if ( ! empty( $def['required'] ) && '' === $val ) {
				/* translators: %s: field label */
				$errors[] = sprintf( __( '%s is required.', 'gx-zoho-bookings' ), $def['label'] );
				continue;
			}

			if ( '' !== $val ) {
				// Zoho keys additional_fields by the field label shown in Bookings.
				$values[ $def['label'] ] = $val;
			}
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}
}
