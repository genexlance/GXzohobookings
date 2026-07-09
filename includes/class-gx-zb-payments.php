<?php
/**
 * GX Zoho Bookings Payments
 *
 * Front-end booking form with optional Stripe payment integration.
 *
 * @package GX_ZB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class GX_ZB_Payments
 *
 * Shortcode [zoho_bookings_book] with AJAX staff/slots and payment handling.
 */
final class GX_ZB_Payments {

	/**
	 * Singleton instance.
	 *
	 * @var self
	 */
	private static $instance = null;

	/**
	 * Whether assets have been enqueued.
	 *
	 * @var bool
	 */
	private $assets_enqueued = false;

	/**
	 * Get instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Register hooks.
	 */
	public function register() {
		add_shortcode( 'zoho_bookings_book', array( $this, 'shortcode' ) );
		add_action( 'wp_ajax_gx_zb_staff', array( $this, 'ajax_staff' ) );
		add_action( 'wp_ajax_nopriv_gx_zb_staff', array( $this, 'ajax_staff' ) );
		add_action( 'wp_ajax_gx_zb_slots', array( $this, 'ajax_slots' ) );
		add_action( 'wp_ajax_nopriv_gx_zb_slots', array( $this, 'ajax_slots' ) );
		add_action( 'wp_ajax_gx_zb_fields', array( $this, 'ajax_fields' ) );
		add_action( 'wp_ajax_nopriv_gx_zb_fields', array( $this, 'ajax_fields' ) );
		add_action( 'wp_ajax_gx_zb_resources', array( $this, 'ajax_resources' ) );
		add_action( 'wp_ajax_nopriv_gx_zb_resources', array( $this, 'ajax_resources' ) );
		add_action( 'admin_post_gx_zb_book_submit', array( $this, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_gx_zb_book_submit', array( $this, 'handle_submit' ) );
		add_action( 'template_redirect', array( $this, 'handle_return' ) );
	}

	/**
	 * Enqueue front-end assets.
	 */
	private function enqueue_assets() {
		if ( ! wp_style_is( 'gx-zb-frontend', 'enqueued' ) ) {
			wp_enqueue_style( 'gx-zb-frontend', GX_ZB_URL . 'assets/css/gx-zb-frontend.css', array(), GX_ZB_VERSION );
		}
		if ( ! wp_script_is( 'gx-zb-book', 'enqueued' ) ) {
			wp_enqueue_script( 'gx-zb-book', GX_ZB_URL . 'assets/js/gx-zb-book.js', array(), GX_ZB_VERSION, true );
			wp_localize_script(
				'gx-zb-book',
				'gxZbBook',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'gx_zb_book' ),
				)
			);
		}
	}

	/**
	 * Shortcode output – full booking form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Form HTML.
	 */
	public function shortcode( $atts ) {
		if ( ! $this->assets_enqueued ) {
			$this->enqueue_assets();
			$this->assets_enqueued = true;
		}

		$atts = shortcode_atts(
			array(
				'service'       => '',
				'show_phone'    => 'yes',
				'require_phone' => 'yes',
				'show_notes'    => 'no',
			),
			$atts,
			'zoho_bookings_book'
		);

		$truthy        = array( 'yes', 'true', '1' );
		$show_phone    = in_array( strtolower( (string) $atts['show_phone'] ), $truthy, true );
		$require_phone = $show_phone && in_array( strtolower( (string) $atts['require_phone'] ), $truthy, true );
		$show_notes    = in_array( strtolower( (string) $atts['show_notes'] ), $truthy, true );

		// Preselected service: shortcode attribute (service landing pages pass
		// their own id) — a gx_zb_service query arg wins so links can deep-link.
		$preselect = sanitize_text_field( (string) $atts['service'] );
		if ( isset( $_GET['gx_zb_service'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display hint.
			$preselect = sanitize_text_field( wp_unslash( $_GET['gx_zb_service'] ) );
		}

		$output       = '';
		$services     = array();
		$api_client   = GX_ZB_API_Client::instance();

		// Fetch services for the dropdown.
		try {
			$services = $api_client->get_services();
		} catch ( Exception $e ) {
			$services = array(); // Fallback to empty.
		}

		// Display result transient message if present.
		if ( isset( $_GET['gx_zb_book_result'] ) ) {
			$result_key = sanitize_text_field( wp_unslash( $_GET['gx_zb_book_result'] ) );
			$result     = get_transient( 'gx_zb_result_' . $result_key );
			if ( is_array( $result ) && isset( $result['message'] ) ) {
				$type = isset( $result['type'] ) ? sanitize_html_class( $result['type'] ) : 'info';
				if ( ! in_array( $type, array( 'success', 'error', 'cancel', 'info' ), true ) ) {
					$type = 'info';
				}
				delete_transient( 'gx_zb_result_' . $result_key );

				// Successful booking: replace the form with a confirmation panel
				// offering Google Calendar / .ics links. The form only returns via
				// the "Book another appointment" link (result param stripped).
				if ( 'success' === $type && ! empty( $result['event'] ) && is_array( $result['event'] ) && class_exists( 'GX_ZB_Calendar' ) ) {
					$book_another = remove_query_arg( 'gx_zb_book_result' );
					return $output . GX_ZB_Calendar::confirmation_panel( $result['event'], $result['message'], $book_another );
				}

				$output .= '<div class="gx-zb-book-result ' . esc_attr( $type ) . '">' . esc_html( $result['message'] ) . '</div>';
			}
		}

		// Build return URL for the form (current page).
		$return_url = get_permalink();
		if ( false === $return_url ) {
			$return_url = home_url();
		}

		// Form.
		$output .= '<form class="gx-zb-book-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		$output .= '<input type="hidden" name="action" value="gx_zb_book_submit">';
		$output .= '<input type="hidden" name="return_url" value="' . esc_url( $return_url ) . '">';
		// Returned, not echoed — an echo would land outside the form markup and
		// the nonce would never be submitted.
		$output .= wp_nonce_field( 'gx_zb_book_submit', '_wpnonce_gx_zb_book_submit', true, false );

		// Service select.
		$output .= '<div class="gx-zb-field">';
		$output .= '<label for="gx-zb-book-service">' . esc_html__( 'Service', 'gx-zoho-bookings' ) . '</label>';
		$output .= '<select id="gx-zb-book-service" name="service_id" required>';
		$output .= '<option value="">' . esc_html__( 'Select a service', 'gx-zoho-bookings' ) . '</option>';
		if ( is_array( $services ) ) {
			foreach ( $services as $service ) {
				// The API client returns associative arrays; ids are opaque strings.
				$sid   = isset( $service['id'] ) ? (string) $service['id'] : ( isset( $service['service_id'] ) ? (string) $service['service_id'] : '' );
				$sname = isset( $service['name'] ) ? (string) $service['name'] : '';
				$scost = isset( $service['cost'] ) ? floatval( $service['cost'] ) : 0;
				$stype = isset( $service['service_type'] ) ? (string) $service['service_type'] : 'one_on_one';
				$sdur  = isset( $service['duration'] ) ? (int) $service['duration'] : 30;
				if ( '' === $sid ) {
					continue;
				}
				$output .= '<option value="' . esc_attr( $sid ) . '" data-cost="' . esc_attr( $scost ) . '" data-service-type="' . esc_attr( $stype ) . '" data-duration="' . esc_attr( $sdur ) . '"' . selected( $sid, $preselect, false ) . '>';
				$output .= esc_html( $sname );
				if ( $scost > 0 ) {
					$output .= ' (' . esc_html( strtoupper( GX_ZB_Stripe::instance()->currency() ) . ' ' . number_format_i18n( $scost, 2 ) ) . ')';
				}
				$output .= '</option>';
			}
		}
		$output .= '</select>';
		$output .= '</div>';

		// Staff select (populated by JS; hidden for resource-type services).
		$output .= '<div class="gx-zb-field" id="gx-zb-staff-field">';
		$output .= '<label for="gx-zb-book-staff">' . esc_html__( 'Staff', 'gx-zoho-bookings' ) . '</label>';
		$output .= '<select id="gx-zb-book-staff" name="staff_id" required disabled>';
		$output .= '<option value="">' . esc_html__( 'Select a service first', 'gx-zoho-bookings' ) . '</option>';
		$output .= '</select>';
		$output .= '</div>';

		// Resource select (paid: resource booking). Hidden until a resource-type
		// service is chosen; JS fills it via the gx_zb_resources AJAX action.
		$output .= '<div class="gx-zb-field" id="gx-zb-resource-field" style="display:none;">';
		$output .= '<label for="gx-zb-book-resource">' . esc_html__( 'Resource', 'gx-zoho-bookings' ) . '</label>';
		$output .= '<select id="gx-zb-book-resource" name="resource_id" disabled>';
		$output .= '<option value="">' . esc_html__( 'Select a service first', 'gx-zoho-bookings' ) . '</option>';
		$output .= '</select>';
		$output .= '</div>';

		// Date.
		$output .= '<div class="gx-zb-field">';
		$output .= '<label for="gx-zb-book-date">' . esc_html__( 'Date', 'gx-zoho-bookings' ) . '</label>';
		$output .= '<input type="date" id="gx-zb-book-date" name="date" required>';
		$output .= '</div>';

		// Slots container (staff services).
		$output .= '<div class="gx-zb-slots" id="gx-zb-book-slots">';
		$output .= '<p>' . esc_html__( 'Select a staff and date to see available slots', 'gx-zoho-bookings' ) . '</p>';
		$output .= '</div>';

		// Hidden slot input.
		$output .= '<input type="hidden" id="gx-zb-book-slot" name="slot" value="">';

		// Start-time input (resource services book a start time + duration range).
		$output .= '<div class="gx-zb-field" id="gx-zb-resource-time-field" style="display:none;">';
		$output .= '<label for="gx-zb-book-time">' . esc_html__( 'Start time', 'gx-zoho-bookings' ) . '</label>';
		$output .= '<input type="time" id="gx-zb-book-time" name="resource_time" value="">';
		$output .= '</div>';

		// Customer details.
		$output .= '<div class="gx-zb-field">';
		$output .= '<label for="gx-zb-book-name">' . esc_html__( 'Your Name', 'gx-zoho-bookings' ) . '</label>';
		$output .= '<input type="text" id="gx-zb-book-name" name="name" required>';
		$output .= '</div>';

		$output .= '<div class="gx-zb-field">';
		$output .= '<label for="gx-zb-book-email">' . esc_html__( 'Email', 'gx-zoho-bookings' ) . '</label>';
		$output .= '<input type="email" id="gx-zb-book-email" name="email" required>';
		$output .= '</div>';

		if ( $show_phone ) {
			$phone_label = __( 'Phone', 'gx-zoho-bookings' );
			if ( ! $require_phone ) {
				$phone_label .= ' ' . __( '(optional)', 'gx-zoho-bookings' );
			}
			$output .= '<div class="gx-zb-field">';
			$output .= '<label for="gx-zb-book-phone">' . esc_html( $phone_label ) . '</label>';
			$output .= '<input type="tel" id="gx-zb-book-phone" name="phone"' . ( $require_phone ? ' required' : '' ) . '>';
			$output .= '</div>';
			// Server-side validation reads this flag; forging it only relaxes an
			// optional field on the site's own form, it grants nothing.
			$output .= '<input type="hidden" name="phone_required" value="' . ( $require_phone ? '1' : '0' ) . '">';
		}

		if ( $show_notes ) {
			$output .= '<div class="gx-zb-field">';
			$output .= '<label for="gx-zb-book-notes">' . esc_html__( 'Notes (optional)', 'gx-zoho-bookings' ) . '</label>';
			$output .= '<textarea id="gx-zb-book-notes" name="notes" rows="3" maxlength="500"></textarea>';
			$output .= '</div>';
		}

		// Custom fields (paid: additional_fields). Rendered for a preselected
		// service — the primary paid flow is a landing page bound to one service.
		// The dropdown flow fills #gx-zb-custom-fields-slot via AJAX (gx_zb_fields).
		if ( '' !== $preselect && class_exists( 'GX_ZB_Fields' ) ) {
			$output .= GX_ZB_Fields::instance()->render_inputs( $preselect );
		}
		$output .= '<div id="gx-zb-custom-fields-slot"></div>';

		// Payment note (populated by JS from the selected service).
		$output .= '<div class="gx-zb-pay-note" id="gx-zb-pay-note" style="display:none;"></div>';

		$output .= '<button type="submit" id="gx-zb-book-submit" class="gx-zb-submit-button" disabled>' . esc_html__( 'Book Appointment', 'gx-zoho-bookings' ) . '</button>';
		$output .= '</form>';

		return $output;
	}

	/**
	 * AJAX handler: custom-field inputs HTML for a service (paid).
	 *
	 * @since 2.0.0
	 */
	public function ajax_fields() {
		check_ajax_referer( 'gx_zb_book' );

		$service_id = isset( $_POST['service_id'] ) ? sanitize_text_field( wp_unslash( $_POST['service_id'] ) ) : '';
		if ( '' === $service_id || ! class_exists( 'GX_ZB_Fields' ) ) {
			wp_send_json_success( array( 'html' => '' ) );
		}
		wp_send_json_success( array( 'html' => GX_ZB_Fields::instance()->render_inputs( $service_id ) ) );
	}

	/**
	 * AJAX handler: bookable resources for a service (paid: resource booking).
	 *
	 * @since 2.0.0
	 */
	public function ajax_resources() {
		check_ajax_referer( 'gx_zb_book' );

		$service_id = isset( $_POST['service_id'] ) ? sanitize_text_field( wp_unslash( $_POST['service_id'] ) ) : '';
		$resources  = GX_ZB_API_Client::instance()->get_resources( $service_id );
		if ( is_wp_error( $resources ) ) {
			wp_send_json_error( $resources->get_error_message() );
		}

		$out = array();
		foreach ( (array) $resources as $res ) {
			if ( ! is_array( $res ) ) {
				continue;
			}
			$rid = isset( $res['id'] ) ? (string) $res['id'] : '';
			if ( '' === $rid ) {
				continue;
			}
			$out[] = array(
				'id'   => $rid,
				'name' => isset( $res['name'] ) ? sanitize_text_field( $res['name'] ) : $rid,
			);
		}
		wp_send_json_success( $out );
	}

	/**
	 * AJAX handler: get staff for a service.
	 */
	public function ajax_staff() {
		check_ajax_referer( 'gx_zb_book' );

		$service_id = isset( $_POST['service_id'] ) ? sanitize_text_field( wp_unslash( $_POST['service_id'] ) ) : '';
		if ( '' === $service_id ) {
			wp_send_json_error( __( 'Invalid service.', 'gx-zoho-bookings' ) );
		}

		$api_client = GX_ZB_API_Client::instance();
		$staff      = $api_client->get_staff( $service_id );

		if ( is_wp_error( $staff ) ) {
			wp_send_json_error( $staff->get_error_message() );
		}

		// Staff removed on this site are not bookable.
		if ( class_exists( 'GX_ZB_Staff_Meta' ) ) {
			$staff = GX_ZB_Staff_Meta::filter_visible( (array) $staff );
		}

		$data = array();
		foreach ( (array) $staff as $member ) {
			if ( ! is_array( $member ) ) {
				continue;
			}
			$mid = isset( $member['id'] ) ? (string) $member['id'] : ( isset( $member['staff_id'] ) ? (string) $member['staff_id'] : '' );
			if ( '' === $mid ) {
				continue;
			}
			$data[] = array(
				'id'   => $mid,
				'name' => isset( $member['name'] ) ? sanitize_text_field( $member['name'] ) : ( isset( $member['staff_name'] ) ? sanitize_text_field( $member['staff_name'] ) : '' ),
			);
		}
		wp_send_json_success( $data );
	}

	/**
	 * AJAX handler: get available slots.
	 */
	public function ajax_slots() {
		check_ajax_referer( 'gx_zb_book' );

		$service_id = isset( $_POST['service_id'] ) ? sanitize_text_field( wp_unslash( $_POST['service_id'] ) ) : '';
		$staff_id   = isset( $_POST['staff_id'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_id'] ) ) : '';
		$date       = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';

		if ( '' === $service_id || '' === $staff_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( __( 'Missing or invalid parameters.', 'gx-zoho-bookings' ) );
		}

		// Convert the HTML date (Y-m-d) to Zoho's dd-MMM-yyyy for the slots call.
		$zoho_date = $date;
		$dt        = DateTime::createFromFormat( 'Y-m-d', $date );
		if ( $dt ) {
			$zoho_date = $dt->format( 'd-M-Y' );
		}

		$api_client = GX_ZB_API_Client::instance();
		$slots      = $api_client->get_available_slots( $service_id, $staff_id, $zoho_date );

		if ( is_wp_error( $slots ) ) {
			wp_send_json_error( $slots->get_error_message() );
		}

		$data = array_map( 'sanitize_text_field', (array) $slots );
		wp_send_json_success( $data );
	}

	/**
	 * Form submission handler – free or paid booking flow.
	 */
	public function handle_submit() {
		// Verify nonce.
		if ( ! isset( $_POST['_wpnonce_gx_zb_book_submit'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_gx_zb_book_submit'] ) ), 'gx_zb_book_submit' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'gx-zoho-bookings' ) );
		}

		// Sanitize inputs. Service/staff ids are opaque strings, never integers.
		$service_id   = isset( $_POST['service_id'] ) ? sanitize_text_field( wp_unslash( $_POST['service_id'] ) ) : '';
		$staff_id     = isset( $_POST['staff_id'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_id'] ) ) : '';
		$resource_id  = isset( $_POST['resource_id'] ) ? sanitize_text_field( wp_unslash( $_POST['resource_id'] ) ) : '';
		$res_time     = isset( $_POST['resource_time'] ) ? sanitize_text_field( wp_unslash( $_POST['resource_time'] ) ) : '';
		$is_resource  = ( '' !== $resource_id );
		$date       = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
		$slot       = sanitize_text_field( wp_unslash( $_POST['slot'] ?? '' ) );
		$name       = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$email      = '';
		if ( isset( $_POST['email'] ) && is_email( $_POST['email'] ) ) {
			$email = sanitize_email( wp_unslash( $_POST['email'] ) );
		}
		$phone      = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$notes      = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
		$notes      = ( strlen( $notes ) > 500 ) ? substr( $notes, 0, 500 ) : $notes;
		$phone_req  = isset( $_POST['phone_required'] ) && '1' === $_POST['phone_required'];
		// wp_validate_redirect blocks off-site return_url values (open redirect).
		$return_url = esc_url_raw( wp_validate_redirect( wp_unslash( $_POST['return_url'] ?? '' ), home_url() ) );

		// Collect errors.
		$errors = array();
		if ( '' === $service_id ) {
			$errors[] = __( 'Service is required.', 'gx-zoho-bookings' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$errors[] = __( 'A valid date is required.', 'gx-zoho-bookings' );
		}
		if ( $is_resource ) {
			// Resource booking: a resource + start time replace staff + slot.
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $res_time ) ) {
				$errors[] = __( 'A valid start time is required.', 'gx-zoho-bookings' );
			}
		} else {
			if ( '' === $staff_id ) {
				$errors[] = __( 'Staff is required.', 'gx-zoho-bookings' );
			}
			if ( empty( $slot ) ) {
				$errors[] = __( 'Time slot is required.', 'gx-zoho-bookings' );
			}
		}
		if ( empty( $name ) ) {
			$errors[] = __( 'Name is required.', 'gx-zoho-bookings' );
		}
		if ( empty( $email ) ) {
			$errors[] = __( 'Valid email is required.', 'gx-zoho-bookings' );
		}
		if ( $phone_req && empty( $phone ) ) {
			$errors[] = __( 'Phone is required.', 'gx-zoho-bookings' );
		}

		// Custom fields (paid: additional_fields). Collected + validated site-side.
		$custom_fields = array();
		if ( '' !== $service_id && class_exists( 'GX_ZB_Fields' ) ) {
			$cf_source = isset( $_POST['gx_zb_cf'] ) && is_array( $_POST['gx_zb_cf'] ) ? wp_unslash( $_POST['gx_zb_cf'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized inside collect().
			$collected = GX_ZB_Fields::instance()->collect( $service_id, $cf_source );
			$custom_fields = $collected['values'];
			if ( ! empty( $collected['errors'] ) ) {
				$errors = array_merge( $errors, $collected['errors'] );
			}
		}

		if ( ! empty( $errors ) ) {
			$this->redirect_with_result( $return_url, 'error', implode( ' ', $errors ) );
		}

		// Retrieve service cost + name server-side (never trust the client). API
		// client returns associative arrays; ids are opaque strings.
		$services         = GX_ZB_API_Client::instance()->get_services();
		$cost             = 0.0;
		$service_name     = '';
		$service_duration = 30;
		if ( is_array( $services ) ) {
			foreach ( $services as $service ) {
				$sid = isset( $service['id'] ) ? (string) $service['id'] : ( isset( $service['service_id'] ) ? (string) $service['service_id'] : '' );
				if ( $sid === $service_id ) {
					$cost         = isset( $service['cost'] ) ? floatval( $service['cost'] ) : 0.0;
					$service_name = isset( $service['name'] ) ? (string) $service['name'] : '';
					if ( isset( $service['duration'] ) && (int) $service['duration'] > 0 ) {
						$service_duration = (int) $service['duration'];
					}
					break;
				}
			}
		}
		if ( '' === $service_name ) {
			$this->redirect_with_result( $return_url, 'error', __( 'Invalid service selected.', 'gx-zoho-bookings' ) );
		}

		// Staff removed on this site cannot be booked, even by a forged POST.
		if ( ! $is_resource && class_exists( 'GX_ZB_Staff_Meta' ) && GX_ZB_Staff_Meta::is_hidden( $staff_id ) ) {
			$this->redirect_with_result( $return_url, 'error', __( 'Invalid staff selected.', 'gx-zoho-bookings' ) );
		}

		// Build the exact payload the API client expects: from_time in Zoho
		// datetime format + customer_details. Combine the Y-m-d date with the
		// slot ("10:00 AM" or "14:30") — or the start time for resource bookings.
		$from_time = $this->build_from_time( $date, $is_resource ? $res_time : $slot );
		if ( '' === $from_time ) {
			$this->redirect_with_result( $return_url, 'error', __( 'Invalid time slot.', 'gx-zoho-bookings' ) );
		}

		// Resource bookings require an end time = start + service duration.
		$to_time = '';
		if ( $is_resource ) {
			$start_dt = DateTime::createFromFormat( 'd-M-Y H:i:s', $from_time );
			if ( ! $start_dt ) {
				$this->redirect_with_result( $return_url, 'error', __( 'Invalid start time.', 'gx-zoho-bookings' ) );
			}
			$start_dt->modify( '+' . max( 1, $service_duration ) . ' minutes' );
			$to_time = $start_dt->format( 'd-M-Y H:i:s' );
		}

		$customer_details = array(
			'name'  => $name,
			'email' => $email,
		);
		if ( '' !== $phone ) {
			$customer_details['phone_number'] = $phone;
		}
		if ( '' !== $notes ) {
			$customer_details['notes'] = $notes;
		}

		$appt_args = array(
			'service_id'       => $service_id,
			'from_time'        => $from_time,
			'timezone'         => wp_timezone_string(),
			'customer_details' => $customer_details,
		);
		if ( $is_resource ) {
			$appt_args['resource_id'] = $resource_id;
			$appt_args['to_time']     = $to_time;
		} else {
			$appt_args['staff_id'] = $staff_id;
		}
		if ( ! empty( $custom_fields ) ) {
			$appt_args['additional_fields'] = $custom_fields;
		}

		$stripe           = GX_ZB_Stripe::instance();
		$is_paid_booking  = ( $cost > 0 && $stripe->is_enabled() );

		// Calendar-link event details, carried through to the confirmation panel.
		// A staff member's video-conference link becomes the event location so
		// it lands in the attendee's calendar entry. Resource bookings have no staff.
		$video_url = ( ! $is_resource && class_exists( 'GX_ZB_Staff_Meta' ) ) ? GX_ZB_Staff_Meta::video_url( $staff_id ) : '';

		/* translators: %s: site name */
		$description = sprintf( __( 'Appointment booked at %s', 'gx-zoho-bookings' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		if ( '' !== $video_url ) {
			/* translators: %s: video call URL */
			$description .= "\n" . sprintf( __( 'Join video call: %s', 'gx-zoho-bookings' ), $video_url );
		}

		$event_dt = DateTime::createFromFormat( 'd-M-Y H:i:s', $from_time );
		$event    = array(
			'title'        => $service_name,
			'start'        => $event_dt ? $event_dt->format( 'Y-m-d H:i:s' ) : '',
			'duration_min' => $service_duration,
			'description'  => $description,
			'location'     => ( '' !== $video_url ) ? $video_url : home_url( '/' ),
			'video_url'    => $video_url,
		);

		// CRM sync payload (paid: pushed to Zoho CRM after a confirmed booking).
		$crm_booking = array(
			'name'         => $name,
			'email'        => $email,
			'phone'        => $phone,
			'service_name' => $service_name,
			'staff_name'   => '',
			'start_time'   => $from_time,
			'end_time'     => $to_time,
			'duration'     => $service_duration,
			'timezone'     => wp_timezone_string(),
			'cost'         => $cost,
			'notes'        => $notes,
		);

		if ( ! $is_paid_booking ) {
			// Free path — book immediately.
			$result = GX_ZB_API_Client::instance()->create_appointment( $appt_args );
			if ( $result && ! is_wp_error( $result ) ) {
				$this->push_to_crm( $crm_booking );
				$this->redirect_with_result( $return_url, 'success', __( 'Booking confirmed!', 'gx-zoho-bookings' ), $event );
			} else {
				$error_msg = is_wp_error( $result ) ? $result->get_error_message() : __( 'Booking failed.', 'gx-zoho-bookings' );
				$this->redirect_with_result( $return_url, 'error', $error_msg );
			}
			exit; // redirect_with_result already exits; defensive.
		}

		// Paid path – stash the final appointment args and start Stripe Checkout.
		$token         = wp_generate_password( 20, false, false );
		$transient_key = 'gx_zb_pending_' . $token;
		$pending_data  = array(
			'appt_args'  => $appt_args,
			'amount'     => $cost,
			'currency'   => $stripe->currency(),
			'return_url' => $return_url,
			'event'      => $event,
			'crm'        => $crm_booking,
		);
		set_transient( $transient_key, $pending_data, 15 * MINUTE_IN_SECONDS );

		$success_url = add_query_arg(
			array(
				'gx_zb_pay'  => 'return',
				'session_id' => '{CHECKOUT_SESSION_ID}',
			),
			$return_url
		);
		$cancel_url  = add_query_arg( 'gx_zb_pay', 'cancel', $return_url );

		$args = array(
			'amount_cents'   => intval( round( $cost * 100 ) ),
			'currency'       => $pending_data['currency'],
			'product_name'   => $service_name,
			'customer_email' => $email,
			'success_url'    => $success_url,
			'cancel_url'     => $cancel_url,
			'metadata'       => array( 'token' => $token ),
		);

		$session = $stripe->create_checkout_session( $args );
		if ( is_wp_error( $session ) ) {
			delete_transient( $transient_key );
			$this->redirect_with_result( $return_url, 'error', __( 'Payment error', 'gx-zoho-bookings' ) . ': ' . $session->get_error_message() );
		}

		// Redirect to Stripe Checkout.
		wp_redirect( esc_url_raw( $session['url'] ) );
		exit;
	}

	/**
	 * Handle Stripe Checkout return/cancel on the front-end.
	 */
	public function handle_return() {
		if ( ! isset( $_GET['gx_zb_pay'] ) || empty( $_GET['session_id'] ) ) {
			return;
		}

		$action     = sanitize_text_field( wp_unslash( $_GET['gx_zb_pay'] ) );
		$session_id = sanitize_text_field( wp_unslash( $_GET['session_id'] ) );

		// Fallback return URL (will be replaced if transient found).
		$fallback_url = home_url( remove_query_arg( array( 'gx_zb_pay', 'session_id', 'gx_zb_book_result' ) ) );

		$stripe = GX_ZB_Stripe::instance();

		if ( 'return' === $action ) {
			$session = $stripe->retrieve_session( $session_id );
			if ( is_wp_error( $session ) ) {
				$this->redirect_with_result( $fallback_url, 'error', $session->get_error_message() );
			}

			if ( isset( $session['payment_status'] ) && 'paid' === $session['payment_status'] ) {
				$token = isset( $session['metadata']['token'] ) ? sanitize_text_field( $session['metadata']['token'] ) : '';
				if ( ! $token ) {
					$this->redirect_with_result( $fallback_url, 'error', __( 'Missing booking reference.', 'gx-zoho-bookings' ) );
				}

				$transient_key = 'gx_zb_pending_' . $token;
				$pending       = get_transient( $transient_key );
				if ( ! is_array( $pending ) || ! isset( $pending['appt_args'] ) ) {
					delete_transient( $transient_key );
					$this->redirect_with_result( $fallback_url, 'error', __( 'Booking detail expired or not found.', 'gx-zoho-bookings' ) );
				}

				$appt_args    = $pending['appt_args'];
				$return_url   = isset( $pending['return_url'] ) ? esc_url_raw( $pending['return_url'] ) : $fallback_url;
				$paid_amount  = isset( $pending['amount'] ) ? floatval( $pending['amount'] ) : 0;
				$currency     = isset( $pending['currency'] ) ? strtoupper( sanitize_text_field( $pending['currency'] ) ) : '';

				$result = GX_ZB_API_Client::instance()->create_appointment( $appt_args );
				delete_transient( $transient_key );

				if ( $result && ! is_wp_error( $result ) ) {
					if ( isset( $pending['crm'] ) && is_array( $pending['crm'] ) ) {
						$this->push_to_crm( $pending['crm'] );
					}
					$message = sprintf(
						/* translators: 1: currency, 2: amount */
						__( 'Booking confirmed! Amount paid: %1$s %2$.2f', 'gx-zoho-bookings' ),
						$currency,
						$paid_amount
					);
					$conf_event = ( isset( $pending['event'] ) && is_array( $pending['event'] ) ) ? $pending['event'] : array();
					$this->redirect_with_result( $return_url, 'success', $message, $conf_event );
				} else {
					$error_msg = is_wp_error( $result ) ? $result->get_error_message() : __( 'Booking creation failed after payment.', 'gx-zoho-bookings' );
					$this->redirect_with_result( $return_url, 'error', $error_msg );
				}
			} else {
				$this->redirect_with_result( $fallback_url, 'error', __( 'Payment was not completed.', 'gx-zoho-bookings' ) );
			}
		} elseif ( 'cancel' === $action ) {
			// Optionally clean up pending transient via session metadata.
			$session = $stripe->retrieve_session( $session_id );
			if ( ! is_wp_error( $session ) && isset( $session['metadata']['token'] ) ) {
				$token = sanitize_text_field( $session['metadata']['token'] );
				delete_transient( 'gx_zb_pending_' . $token );
			}
			$this->redirect_with_result( $fallback_url, 'cancel', __( 'Payment cancelled, not booked.', 'gx-zoho-bookings' ) );
		}
	}

	/**
	 * Push a confirmed booking to Zoho CRM, if CRM sync is enabled.
	 *
	 * Best-effort: CRM failures never block or reverse a confirmed booking —
	 * they are logged and swallowed so the customer still sees success.
	 *
	 * @since 2.0.0
	 * @param array $booking Booking payload for GX_ZB_CRM::sync_booking().
	 * @return void
	 */
	private function push_to_crm( array $booking ) {
		if ( ! class_exists( 'GX_ZB_CRM' ) ) {
			return;
		}
		$crm = GX_ZB_CRM::instance();
		if ( ! $crm->is_enabled() ) {
			return;
		}
		$result = $crm->sync_booking( $booking );
		if ( is_wp_error( $result ) ) {
			error_log( 'GX_ZB_CRM sync failed: ' . $result->get_error_message() );
		}
	}

	/**
	 * Combine a Y-m-d date and a slot string into Zoho's dd-MMM-yyyy HH:mm:ss.
	 * Accepts 12-hour ("10:00 AM") or 24-hour ("14:30") slots.
	 *
	 * @param string $date Y-m-d date.
	 * @param string $slot Time slot string.
	 * @return string Zoho datetime, or '' on failure.
	 */
	private function build_from_time( $date, $slot ) {
		$date_obj = DateTime::createFromFormat( 'Y-m-d', $date );
		if ( ! $date_obj ) {
			return '';
		}
		$time_obj = DateTime::createFromFormat( 'h:i A', trim( $slot ) );
		if ( ! $time_obj ) {
			$time_obj = DateTime::createFromFormat( 'H:i', trim( $slot ) );
		}
		if ( ! $time_obj ) {
			return '';
		}
		$date_obj->setTime( (int) $time_obj->format( 'H' ), (int) $time_obj->format( 'i' ), 0 );
		return $date_obj->format( 'd-M-Y H:i:s' );
	}

	/**
	 * Store a transient result and redirect.
	 *
	 * @param string $return_url URL to redirect to.
	 * @param string $type       Result type (success/error/cancel).
	 * @param string $message    Display message.
	 */
	private function redirect_with_result( $return_url, $type, $message, $event = array() ) {
		$key  = wp_generate_password( 8, false, false );
		$data = array(
			'type'    => $type,
			'message' => $message,
		);
		if ( ! empty( $event ) && is_array( $event ) ) {
			// Booked-appointment details used to render calendar links.
			$data['event'] = $event;
		}
		set_transient( 'gx_zb_result_' . $key, $data, 300 );
		wp_redirect( add_query_arg( 'gx_zb_book_result', $key, $return_url ) );
		exit;
	}
}
