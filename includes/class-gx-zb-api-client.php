<?php
defined( 'ABSPATH' ) || exit;

/**
 * Zoho Bookings API Client.
 *
 * Handles all communication with the Zoho Bookings API v1.
 *
 * @package GX_Zoho_Bookings
 * @since   1.0.0
 */
final class GX_ZB_API_Client {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since 1.0.0
	 * @return GX_ZB_API_Client
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	/**
	 * Retrieve all workspaces.
	 *
	 * @since 1.0.0
	 * @return array|WP_Error Array of workspace data or error.
	 */
	public function get_workspaces() {
		return $this->request( 'GET', 'workspaces' );
	}

	/**
	 * Retrieve services for a given workspace.
	 *
	 * @since 1.0.0
	 * @param string $workspace_id Optional workspace ID.
	 * @return array|WP_Error Array of services or error.
	 */
	public function get_services( $workspace_id = '' ) {
		$params = array();
		if ( ! empty( $workspace_id ) ) {
			$params['workspace_id'] = sanitize_text_field( $workspace_id );
		}
		return $this->request( 'GET', 'services', $params );
	}

	/**
	 * Retrieve staff members for a given service.
	 *
	 * @since 1.0.0
	 * @param string $service_id Optional service ID.
	 * @return array|WP_Error Array of staff or error.
	 */
	public function get_staff( $service_id = '' ) {
		$params = array();
		if ( ! empty( $service_id ) ) {
			$params['service_id'] = sanitize_text_field( $service_id );
		}
		return $this->request( 'GET', 'staffs', $params );
	}

	/**
	 * Retrieve available time slots for a service/staff/date combination.
	 *
	 * @since 1.0.0
	 * @param string $service_id Service ID.
	 * @param string $staff_id   Staff ID.
	 * @param string $date       Date in dd-MMM-yyyy format (e.g. 12-Jan-2025).
	 * @return array|WP_Error Array of slots or error.
	 */
	public function get_available_slots( $service_id, $staff_id, $date ) {
		$params = array(
			'service_id'    => sanitize_text_field( $service_id ),
			'staff_id'      => sanitize_text_field( $staff_id ),
			'selected_date' => sanitize_text_field( $date ),
		);
		return $this->request( 'GET', 'availableslots', $params );
	}

	/**
	 * Retrieve a single appointment by booking ID.
	 *
	 * @since 1.0.0
	 * @param string $booking_id Booking ID.
	 * @return array|WP_Error Appointment data or error.
	 */
	public function get_appointment( $booking_id ) {
		$params = array( 'booking_id' => sanitize_text_field( $booking_id ) );
		return $this->request( 'GET', 'fetchappointment', $params );
	}

	/**
	 * Retrieve available resources.
	 *
	 * @return array|WP_Error Array of resource data on success, WP_Error on failure.
	 */
	public function get_resources() {
		$response = $this->request( 'GET', 'resources' );
		return $response;
	}

	/**
	 * Retrieve appointments based on filters.
	 *
	 * Sends a POST request with form-encoded 'data' parameter.
	 * Data is JSON-encoded array of filters as per Zoho Bookings API.
	 *
	 * @param array $filters {
	 *     Optional. Array of filter parameters.
	 *     @type string $from_time  Start date in 'dd-MMM-yyyy' format.
	 *     @type string $to_time    End date in 'dd-MMM-yyyy' format.
	 *     @type string $service_id Service ID to filter by.
	 *     @type string $staff_id   Staff ID to filter by.
	 *     @type string $status     Appointment status: 'upcoming', 'completed', 'cancel', 'noshow'.
	 * }
	 * @return array|WP_Error Appointment list array on success, WP_Error on failure.
	 *                       Note: response is NOT cached; it's always fresh.
	 */
	public function get_appointments( array $filters = array() ) {
		$params = array(
			'data' => wp_json_encode( $filters ),
		);
		$response = $this->request( 'POST', 'getappointments', $params );
		return $response;
	}

	/**
	 * Create a new appointment via the API.
	 *
	 * Sends form-encoded POST data. On success the API cache is flushed.
	 *
	 * @param array $args {
	 *     Required parameters.
	 *     @type string $service_id      Service ID.
	 *     @type string $staff_id        Staff ID.
	 *     @type string $from_time       Start date/time in 'dd-MMM-yyyy HH:mm:ss' format.
	 *     @type string $timezone        Optional. Timezone string. Defaults to wp_timezone_string().
	 *     @type array  $customer_details Associative array with keys 'name', 'email', 'phone_number'.
	 *     @type string $notes           Optional. Additional notes.
	 * }
	 * @return array|WP_Error Appointment data array on success, WP_Error on failure.
	 */
	public function create_appointment( array $args ) {
		$timezone = isset( $args['timezone'] ) ? $args['timezone'] : wp_timezone_string();
		$customer_json = wp_json_encode( $args['customer_details'] );

		$params = array(
			'service_id'       => $args['service_id'],
			'staff_id'         => $args['staff_id'],
			'from_time'        => $args['from_time'],
			'timezone'         => $timezone,
			'customer_details' => $customer_json,
		);

		if ( ! empty( $args['notes'] ) ) {
			$params['notes'] = $args['notes'];
		}

		$response = $this->request( 'POST', 'appointment', $params );

		if ( ! is_wp_error( $response ) ) {
			$this->flush_cache();
		}

		return $response;
	}

	/**
	 * Reschedule an existing appointment.
	 *
	 * @param string $booking_id The booking ID to reschedule.
	 * @param string $start_time New start time in 'dd-MMM-yyyy HH:mm:ss' format.
	 * @param string $staff_id   Optional. New staff ID.
	 * @return array|WP_Error Appointment data array on success, WP_Error on failure.
	 */
	public function reschedule_appointment( $booking_id, $start_time, $staff_id = '' ) {
		$params = array(
			'booking_id' => $booking_id,
			'start_time' => $start_time,
		);

		if ( ! empty( $staff_id ) ) {
			$params['staff_id'] = $staff_id;
		}

		$response = $this->request( 'POST', 'reschedule', $params );

		if ( ! is_wp_error( $response ) ) {
			$this->flush_cache();
		}

		return $response;
	}

	/**
	 * Update the status of an appointment (complete, cancel, no-show).
	 *
	 * @param string $booking_id Appointment booking ID.
	 * @param string $action     Status action: 'completed', 'cancel', or 'noshow'.
	 * @return array|WP_Error Response array on success, WP_Error on failure.
	 */
	public function update_appointment_status( $booking_id, $action ) {
		if ( ! in_array( $action, array( 'completed', 'cancel', 'noshow' ), true ) ) {
			return new WP_Error( 'gx_zb_api_error', __( 'Invalid appointment action.', 'gx-zoho-bookings' ) );
		}

		$params = array(
			'booking_id' => $booking_id,
			'action'     => $action,
		);

		$response = $this->request( 'POST', 'updateappointment', $params );

		if ( ! is_wp_error( $response ) ) {
			$this->flush_cache();
		}

		return $response;
	}

	/**
	 * Create a new workspace.
	 *
	 * @param string $name Workspace name (2-50 chars, certain special chars forbidden).
	 * @return mixed|WP_Error Decoded response on success, WP_Error on failure.
	 */
	public function create_workspace( $name ) {
		// Pre-validate name per Zoho constraints.
		$name = trim( $name );
		$length = mb_strlen( $name );
		if ( $length < 2 || $length > 50 ) {
			return new WP_Error( 'gx_zb_validation_error', __( 'Workspace name must be between 2 and 50 characters.', 'gx-zoho-bookings' ) );
		}
		if ( preg_match( '#[|/\\\\,?{}<>:;"\'`]#', $name ) ) {
			return new WP_Error( 'gx_zb_validation_error', __( 'Workspace name contains invalid characters: | / \\ , ? { } < > : ; " \' `', 'gx-zoho-bookings' ) );
		}

		$params = array( 'name' => $name );
		$result = $this->request( 'POST', 'createworkspace', $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->flush_cache();
		return $result;
	}

	/**
	 * Create a new service.
	 *
	 * @param array $args {
	 *     Array of service creation parameters.
	 *
	 *     @type string $name             Required. Service name.
	 *     @type string $workspace_id     Required. Workspace ID.
	 *     @type int    $duration         Optional. Duration in minutes.
	 *     @type string $service_type     Optional. 'one_on_one' (default) or 'resource'.
	 *     @type float  $cost             Optional. Numeric cost.
	 *     @type int    $pre_buffer       Optional. Pre-buffer minutes.
	 *     @type int    $post_buffer      Optional. Post-buffer minutes.
	 *     @type string $description      Optional. Description.
	 *     @type array  $assigned_staffs  Optional. Array of staff IDs.
	 *     @type string $meeting_mode     Optional. 'online' or 'offline'.
	 *     @type string $meeting_type     Optional. Required when meeting_mode='online'. Values: 'zohomeeting','zoom','teams','gmeet',''.
	 * }
	 * @return mixed|WP_Error
	 */
	public function create_service( array $args ) {
		if ( empty( $args['name'] ) ) {
			return new WP_Error( 'gx_zb_validation_error', __( 'Service name is required.', 'gx-zoho-bookings' ) );
		}
		if ( empty( $args['workspace_id'] ) ) {
			return new WP_Error( 'gx_zb_validation_error', __( 'Workspace ID is required.', 'gx-zoho-bookings' ) );
		}

		// Meeting mode validation.
		$meeting_mode = isset( $args['meeting_mode'] ) ? $args['meeting_mode'] : '';
		if ( 'online' === $meeting_mode && empty( $args['meeting_type'] ) ) {
			return new WP_Error( 'gx_zb_validation_error', __( 'Meeting type is required when meeting mode is online.', 'gx-zoho-bookings' ) );
		}

		$params = array(
			'name'         => $args['name'],
			'workspace_id' => $args['workspace_id'],
		);

		// Optional fields.
		if ( isset( $args['duration'] ) ) {
			$params['duration'] = absint( $args['duration'] );
		}
		if ( isset( $args['service_type'] ) ) {
			$params['service_type'] = sanitize_text_field( $args['service_type'] );
		}
		if ( isset( $args['cost'] ) ) {
			$params['cost'] = max( 0, (float) $args['cost'] );
		}
		if ( isset( $args['pre_buffer'] ) ) {
			$params['pre_buffer'] = absint( $args['pre_buffer'] );
		}
		if ( isset( $args['post_buffer'] ) ) {
			$params['post_buffer'] = absint( $args['post_buffer'] );
		}
		if ( isset( $args['description'] ) ) {
			$params['description'] = sanitize_textarea_field( $args['description'] );
		}
		if ( ! empty( $args['assigned_staffs'] ) && is_array( $args['assigned_staffs'] ) ) {
			$params['assigned_staffs'] = wp_json_encode( array_map( 'sanitize_text_field', $args['assigned_staffs'] ) );
		}
		if ( isset( $args['meeting_mode'] ) ) {
			$params['meeting_mode'] = sanitize_text_field( $args['meeting_mode'] );
		}
		if ( isset( $args['meeting_type'] ) ) {
			$params['meeting_type'] = sanitize_text_field( $args['meeting_type'] );
		}

		$result = $this->request( 'POST', 'createservice', $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->flush_cache();
		return $result;
	}

	/**
	 * Update an existing service.
	 *
	 * @param array $args {
	 *     Array of service update parameters.
	 *
	 *     @type string $id               Required. Service ID.
	 *     @type string $name             Optional. New name.
	 *     @type int    $duration         Optional. Duration in minutes.
	 *     @type float  $cost             Optional. Numeric cost.
	 *     @type int    $pre_buffer       Optional. Pre-buffer minutes.
	 *     @type int    $post_buffer      Optional. Post-buffer minutes.
	 *     @type string $description      Optional. Description.
	 *     @type array  $assigned_staffs  Optional. JSON array of staff IDs.
	 *     @type string $status           Optional. 'active' or 'in_active'.
	 *     @type string $meeting_mode     Optional. 'online' or 'offline'.
	 *     @type string $meeting_type     Optional. Required when meeting_mode='online'.
	 * }
	 * @return mixed|WP_Error
	 */
	public function update_service( array $args ) {
		if ( empty( $args['id'] ) ) {
			return new WP_Error( 'gx_zb_validation_error', __( 'Service ID is required for update.', 'gx-zoho-bookings' ) );
		}

		// Meeting mode validation.
		$meeting_mode = isset( $args['meeting_mode'] ) ? $args['meeting_mode'] : '';
		if ( 'online' === $meeting_mode && empty( $args['meeting_type'] ) ) {
			return new WP_Error( 'gx_zb_validation_error', __( 'Meeting type is required when meeting mode is online.', 'gx-zoho-bookings' ) );
		}

		$params = array( 'id' => $args['id'] );

		// Optional fields (only add if set).
		if ( isset( $args['name'] ) ) {
			$params['name'] = sanitize_text_field( $args['name'] );
		}
		if ( isset( $args['duration'] ) ) {
			$params['duration'] = absint( $args['duration'] );
		}
		if ( isset( $args['cost'] ) ) {
			$params['cost'] = max( 0, (float) $args['cost'] );
		}
		if ( isset( $args['pre_buffer'] ) ) {
			$params['pre_buffer'] = absint( $args['pre_buffer'] );
		}
		if ( isset( $args['post_buffer'] ) ) {
			$params['post_buffer'] = absint( $args['post_buffer'] );
		}
		if ( isset( $args['description'] ) ) {
			$params['description'] = sanitize_textarea_field( $args['description'] );
		}
		if ( ! empty( $args['assigned_staffs'] ) && is_array( $args['assigned_staffs'] ) ) {
			$params['assigned_staffs'] = wp_json_encode( array_map( 'sanitize_text_field', $args['assigned_staffs'] ) );
		}
		if ( isset( $args['status'] ) ) {
			$valid_statuses = array( 'active', 'in_active' );
			if ( in_array( $args['status'], $valid_statuses, true ) ) {
				$params['status'] = $args['status'];
			}
		}
		if ( isset( $args['meeting_mode'] ) ) {
			$params['meeting_mode'] = sanitize_text_field( $args['meeting_mode'] );
		}
		if ( isset( $args['meeting_type'] ) ) {
			$params['meeting_type'] = sanitize_text_field( $args['meeting_type'] );
		}

		$result = $this->request( 'POST', 'editservice', $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->flush_cache();
		return $result;
	}

	/**
	 * Delete a service permanently.
	 *
	 * @param string $service_id The service ID to delete.
	 * @return mixed|WP_Error
	 */
	public function delete_service( $service_id ) {
		if ( empty( $service_id ) ) {
			return new WP_Error( 'gx_zb_validation_error', __( 'Service ID is required.', 'gx-zoho-bookings' ) );
		}

		$params = array( 'id' => $service_id );
		$result = $this->request( 'POST', 'deleteservice', $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->flush_cache();
		return $result;
	}

	/**
	 * Add one or more staff members.
	 *
	 * Accepts a single staff associative array or a list of staff arrays.
	 *
	 * @param array $staff {
	 *     Single staff array or array of staff arrays.
	 *     Each staff array must contain:
	 *
	 *     @type string $name               Required. Staff member's name.
	 *     @type string $email              Required. Staff email.
	 *     @type string $gender             Optional. Gender.
	 *     @type string $role               Optional. 'Admin', 'Manager', or 'Staff' (default).
	 *     @type string $phone              Optional. Phone number.
	 *     @type string $designation        Optional. Designation.
	 *     @type string $additional_info    Optional. Additional information.
	 *     @type array  $assigned_services  Optional. Array of service IDs.
	 * }
	 * @return mixed|WP_Error
	 */
	public function add_staff( array $staff ) {
		// Normalize to array of staff objects.
		if ( isset( $staff['name'] ) || isset( $staff['email'] ) ) {
			// Assume single staff member.
			$staff_list = array( $staff );
		} else {
			$staff_list = $staff;
		}

		if ( empty( $staff_list ) ) {
			return new WP_Error( 'gx_zb_validation_error', __( 'No staff data provided.', 'gx-zoho-bookings' ) );
		}

		$clean_list = array();
		foreach ( $staff_list as $k => $member ) {
			if ( empty( $member['name'] ) || empty( $member['email'] ) ) {
				return new WP_Error( 'gx_zb_validation_error', __( 'Each staff member must have a name and email.', 'gx-zoho-bookings' ) );
			}
			if ( ! is_email( $member['email'] ) ) {
				return new WP_Error( 'gx_zb_validation_error', __( 'Invalid email address.', 'gx-zoho-bookings' ) );
			}

			$clean = array(
				'name'  => sanitize_text_field( $member['name'] ),
				'email' => sanitize_email( $member['email'] ),
			);

			// Optional fields.
			if ( isset( $member['gender'] ) ) {
				$clean['gender'] = sanitize_text_field( $member['gender'] );
			}
			if ( isset( $member['role'] ) ) {
				$valid_roles = array( 'Admin', 'Manager', 'Staff' );
				$role = sanitize_text_field( $member['role'] );
				if ( in_array( $role, $valid_roles, true ) ) {
					$clean['role'] = $role;
				}
			}
			if ( isset( $member['phone'] ) ) {
				$clean['phone'] = sanitize_text_field( $member['phone'] );
			}
			if ( isset( $member['designation'] ) ) {
				$clean['designation'] = sanitize_text_field( $member['designation'] );
			}
			if ( isset( $member['additional_info'] ) ) {
				$clean['additional_info'] = sanitize_textarea_field( $member['additional_info'] );
			}
			if ( ! empty( $member['assigned_services'] ) && is_array( $member['assigned_services'] ) ) {
				$clean['assigned_services'] = array_map( 'sanitize_text_field', $member['assigned_services'] );
			}

			$clean_list[] = $clean;
		}

		$staff_map = array( 'data' => $clean_list );
		$params = array( 'staffMap' => wp_json_encode( $staff_map ) );

		$result = $this->request( 'POST', 'addstaff', $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// addstaff answers with a per-staff result list (request() passes list
		// envelopes through) — surface any per-staff failure as a WP_Error.
		if ( is_array( $result ) ) {
			$errors = array();
			foreach ( array_values( $result ) as $idx => $staff_response ) {
				if ( is_array( $staff_response ) && isset( $staff_response['status'] ) && 'success' !== $staff_response['status'] ) {
					/* translators: 1: row number, 2: error message */
					$errors[] = sprintf( __( 'Staff #%1$d failed: %2$s', 'gx-zoho-bookings' ), $idx + 1, isset( $staff_response['message'] ) ? $staff_response['message'] : __( 'Unknown error', 'gx-zoho-bookings' ) );
				}
			}
			if ( ! empty( $errors ) ) {
				return new WP_Error( 'gx_zb_staff_add_error', implode( ' | ', $errors ) );
			}
		}

		$this->flush_cache();
		return $result;
	}

	/**
	 * Core HTTP request wrapper.
	 *
	 * @since 1.0.0
	 * @param string $method   HTTP method (GET, POST, etc.).
	 * @param string $endpoint API endpoint name (e.g. 'workspaces').
	 * @param array  $params   Query parameters for GET or body for POST.
	 * @return array|WP_Error Response data array or WP_Error.
	 */
	private function request( $method, $endpoint, array $params = array() ) {
		$oauth = GX_ZB_OAuth::instance();

		if ( ! $oauth->is_connected() ) {
			return new WP_Error(
				'gx_zb_not_connected',
				__( 'Not connected to Zoho.', 'gx-zoho-bookings' )
			);
		}

		$settings = GX_ZB_Settings::instance();
		$base     = $this->get_base_url();

		$url = trailingslashit( $base ) . 'bookings/v1/json/' . $endpoint;

		$cache_key     = '';
		$cache_enabled = false;
		$cache_minutes = 0;

		if ( 'GET' === $method ) {
			if ( ! empty( $params ) ) {
				$url = add_query_arg( $params, $url );
			}
			$cache_minutes = absint( $settings->get( 'cache_minutes', 15 ) );
			if ( $cache_minutes > 0 ) {
				$cache_enabled = true;
				$cache_key     = 'gx_zb_' . md5( $endpoint . serialize( $params ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				$cached        = get_transient( $cache_key );
				if ( false !== $cached && is_array( $cached ) ) {
					return $cached;
				}
			}
		}

		// First attempt.
		$response = $this->do_http_request( $method, $url, $params );

		// If WP_Error, pass through.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		// Handle 401 — attempt token refresh and retry once.
		if ( 401 === $code ) {
			$refresh = $oauth->refresh();
			if ( is_wp_error( $refresh ) ) {
				// Refresh failure (e.g. invalid_grant) — tokens likely cleared, require reconnect.
				return new WP_Error(
					'gx_zb_auth_expired',
					__( 'Authentication expired. Please reconnect your Zoho account.', 'gx-zoho-bookings' )
				);
			}
			// Retry with fresh token.
			$response = $this->do_http_request( $method, $url, $params );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( 401 === absint( wp_remote_retrieve_response_code( $response ) ) ) {
				return new WP_Error(
					'gx_zb_auth_expired',
					__( 'Authentication expired. Please reconnect your Zoho account.', 'gx-zoho-bookings' )
				);
			}
		}

		// Check for HTTP transport errors.
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'gx_zb_http_error', $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['response'] ) ) {
			return new WP_Error(
				'gx_zb_api_error',
				__( 'Invalid API response.', 'gx-zoho-bookings' )
			);
		}

		$response_data = $data['response'];

		// Some endpoints (addstaff) answer with a list of per-item results
		// instead of the status/returnvalue envelope — hand that to the caller.
		if ( is_array( $response_data ) && isset( $response_data[0] ) ) {
			return $response_data;
		}

		$status = isset( $response_data['status'] ) ? $response_data['status'] : '';

		if ( 'success' !== $status ) {
			$error_message = isset( $response_data['errormessage'] )
				? $response_data['errormessage']
				: __( 'Unknown API error.', 'gx-zoho-bookings' );

			return new WP_Error( 'gx_zb_api_error', $error_message );
		}

		$returnvalue = isset( $response_data['returnvalue'] ) ? $response_data['returnvalue'] : array();

		// Unwrap the actual data array.
		if ( is_array( $returnvalue ) ) {
			if ( isset( $returnvalue['data'] ) && is_array( $returnvalue['data'] ) ) {
				$result = $returnvalue['data'];
			} else {
				// Some endpoints return the list directly under returnvalue.
				$result = $returnvalue;
			}
		} else {
			// Safety: wrap unexpected structure.
			$result = array( $returnvalue );
		}

		// Cache successful GET responses.
		if ( $cache_enabled && ! empty( $cache_key ) ) {
			$cache_ttl = $cache_minutes * MINUTE_IN_SECONDS;
			set_transient( $cache_key, $result, $cache_ttl );
			$this->track_transient_key( $cache_key );
		}

		return $result;
	}

	/**
	 * Performs the actual HTTP request with authentication header.
	 *
	 * @since 1.0.0
	 * @param string $method HTTP method.
	 * @param string $url    Full endpoint URL.
	 * @param array  $params Params (ignored for GET as they are already in URL).
	 * @return array|WP_Error Raw response array or error.
	 */
	private function do_http_request( $method, $url, $params ) {
		$oauth        = GX_ZB_OAuth::instance();
		$access_token = $oauth->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Zoho-oauthtoken ' . $access_token,
			),
		);

		if ( 'POST' === $method ) {
			// Zoho Bookings expects form-encoded fields, not a JSON body.
			// Complex values (customer_details, data filters) are JSON strings
			// inside individual form fields, which callers pre-encode.
			$args['body'] = $params;
			return wp_remote_post( $url, $args );
		}

		return wp_remote_get( $url, $args );
	}

	/**
	 * Returns the base API URL, preferring stored api_domain over region mapping.
	 *
	 * @since 1.0.0
	 * @return string Base URL with trailing slash.
	 */
	private function get_base_url() {
		$tokens = get_option( GX_ZB_OPTION_TOKENS, array() );
		if ( ! empty( $tokens['api_domain'] ) ) {
			return trailingslashit( esc_url_raw( $tokens['api_domain'] ) );
		}

		$settings = GX_ZB_Settings::instance();
		$region   = $settings->get( 'region', 'us' );

		return trailingslashit( GX_ZB_Regions::api_base( $region ) );
	}

	/**
	 * Deletes all cached transients and their tracking option.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function flush_cache() {
		$keys = get_option( 'gx_zb_transient_keys', array() );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				delete_transient( $key );
			}
		}
		delete_option( 'gx_zb_transient_keys' );
	}

	/**
	 * Records a transient key for later bulk deletion.
	 *
	 * @since 1.0.0
	 * @param string $key Transient key.
	 * @return void
	 */
	private function track_transient_key( $key ) {
		$keys = get_option( 'gx_zb_transient_keys', array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( 'gx_zb_transient_keys', $keys, false );
		}
	}
}