<?php
defined( 'ABSPATH' ) || exit;

/**
 * GX_ZB_CRM class – handles pushing Bookings into Zoho CRM.
 *
 * @since 2.0.0
 */
final class GX_ZB_CRM {

	/**
	 * Singleton instance.
	 *
	 * @since 2.0.0
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Private constructor to enforce singleton.
	 *
	 * @since 2.0.0
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance.
	 *
	 * @since 2.0.0
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Whether CRM integration is enabled and properly authenticated.
	 *
	 * @since 2.0.0
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) GX_ZB_Settings::instance()->get( 'crm_enabled', false )
			&& GX_ZB_OAuth::instance()->is_connected();
	}

	/**
	 * Synchronise a Zoho Bookings appointment with Zoho CRM.
	 *
	 * Upserts the customer as a contact and creates a corresponding event (meeting).
	 *
	 * @since 2.0.0
	 *
	 * @param array $booking {
	 *     Booking data.
	 *
	 *     @type string $name         Customer full name.
	 *     @type string $email        Customer email.
	 *     @type string $phone        Customer phone.
	 *     @type string $service_name Booked service name.
	 *     @type string $staff_name   Staff member name.
	 *     @type string $start_time   Start time in `dd-MMM-yyyy HH:mm:ss` format.
	 *     @type string $end_time     End time in same format; may be empty.
	 *     @type string $timezone     Timezone string, e.g. `America/New_York`.
	 *     @type float  $cost         Booking cost.
	 *     @type string $notes        Additional notes.
	 * }
	 *
	 * @return array|WP_Error Array with keys `contact_id` and `event_id` on success; WP_Error on failure.
	 */
	public function sync_booking( array $booking ) {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		// This runs immediately after a confirmed (often paid) booking — it must
		// never throw, or the customer could be charged/booked yet hit a fatal.
		try {
			return $this->do_sync_booking( $booking );
		} catch ( Throwable $e ) {
			return new WP_Error( 'gx_zb_crm_error', $e->getMessage() );
		}
	}

	/**
	 * Inner CRM sync implementation, wrapped by sync_booking().
	 *
	 * @since 2.0.0
	 * @param array $booking Booking payload (see sync_booking()).
	 * @return array|WP_Error
	 */
	private function do_sync_booking( array $booking ) {
		$token = GX_ZB_OAuth::instance()->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// Sanitize incoming fields.
		$name         = isset( $booking['name'] ) ? sanitize_text_field( $booking['name'] ) : '';
		$first_name   = '';
		$last_name    = '';

		if ( ! empty( $name ) ) {
			$parts = explode( ' ', trim( $name ) );
			if ( count( $parts ) === 1 ) {
				$last_name = $parts[0];
			} else {
				$last_name  = array_pop( $parts );
				$first_name = implode( ' ', $parts );
			}
		}

		$email        = isset( $booking['email'] ) ? sanitize_text_field( $booking['email'] ) : '';
		$phone        = isset( $booking['phone'] ) ? sanitize_text_field( $booking['phone'] ) : '';
		$service_name = isset( $booking['service_name'] ) ? sanitize_text_field( $booking['service_name'] ) : '';
		$staff_name   = isset( $booking['staff_name'] ) ? sanitize_text_field( $booking['staff_name'] ) : '';
		$cost         = isset( $booking['cost'] ) ? floatval( $booking['cost'] ) : 0.0;
		$notes        = isset( $booking['notes'] ) ? sanitize_text_field( $booking['notes'] ) : '';
		$timezone     = isset( $booking['timezone'] ) ? sanitize_text_field( $booking['timezone'] ) : 'UTC';

		// Upsert contact.
		$contact_payload = array(
			'data'                    => array(
				array(
					'Last_Name'  => $last_name,
					'First_Name' => $first_name,
					'Email'      => $email,
					'Phone'      => $phone,
				),
			),
			'duplicate_check_fields' => array( 'Email' ),
		);

		$contact_resp = $this->crm_request( 'Contacts/upsert', $contact_payload );
		if ( is_wp_error( $contact_resp ) ) {
			return $contact_resp;
		}

		$contact_id = isset( $contact_resp['data'][0]['details']['id'] )
			? $contact_resp['data'][0]['details']['id']
			: '';

		if ( empty( $contact_id ) ) {
			$error_message = isset( $contact_resp['message'] ) ? $contact_resp['message'] : __( 'Could not retrieve Contact ID from CRM.', 'gx-zoho-bookings' );
			return new WP_Error( 'gx_zb_crm_error', $error_message );
		}

		// Build Event description.
		$description = '';
		if ( ! empty( $staff_name ) ) {
			$description .= sprintf( __( 'Staff: %s', 'gx-zoho-bookings' ), $staff_name ) . "\n";
		}
		if ( $cost > 0 ) {
			$description .= sprintf( __( 'Cost: %s', 'gx-zoho-bookings' ), number_format_i18n( $cost, 2 ) ) . "\n";
		}
		if ( ! empty( $notes ) ) {
			$description .= sprintf( __( 'Notes: %s', 'gx-zoho-bookings' ), $notes ) . "\n";
		}
		$description = trim( $description );

		// Build meeting title.
		$title = trim( $service_name . ' — ' . $name );

		// Convert date/times to ISO 8601.
		$start_raw = isset( $booking['start_time'] ) ? $booking['start_time'] : '';
		$start_iso = $this->to_iso8601( $start_raw, $timezone );
		if ( empty( $booking['end_time'] ) ) {
			// If end is empty, add 60 minutes.
			$start_dt = date_create( $start_iso );
			if ( $start_dt ) {
				$start_dt->modify( '+60 minutes' );
				$end_iso = $start_dt->format( 'c' );
			} else {
				$end_iso = $start_iso;
			}
		} else {
			$end_iso = $this->to_iso8601( $booking['end_time'], $timezone );
		}

		// Create Event (Meeting).
		$event_payload = array(
			'data' => array(
				array(
					'Event_Title'    => $title,
					'Start_DateTime' => $start_iso,
					'End_DateTime'   => $end_iso,
					'Description'    => $description,
					'Who_Id'         => array( 'id' => $contact_id ),
				),
			),
		);

		$event_resp = $this->crm_request( 'Events', $event_payload );
		if ( is_wp_error( $event_resp ) ) {
			return $event_resp;
		}

		$event_id = isset( $event_resp['data'][0]['details']['id'] )
			? $event_resp['data'][0]['details']['id']
			: '';

		if ( empty( $event_id ) ) {
			$error_message = isset( $event_resp['message'] ) ? $event_resp['message'] : __( 'Could not retrieve Event ID from CRM.', 'gx-zoho-bookings' );
			return new WP_Error( 'gx_zb_crm_error', $error_message );
		}

		return array(
			'contact_id' => $contact_id,
			'event_id'   => $event_id,
		);
	}

	/**
	 * Perform a Zoho CRM v6 API request.
	 *
	 * @since 2.0.0
	 *
	 * @param string $module_path API endpoint path (e.g., `Contacts/upsert`).
	 * @param array  $payload     Payload to send as JSON body.
	 *
	 * @return array|WP_Error Decoded response array or WP_Error on failure.
	 */
	private function crm_request( $module_path, array $payload ) {
		$region = GX_ZB_Settings::instance()->get( 'region', 'us' );
		$base   = trailingslashit( GX_ZB_Regions::api_base( $region ) ) . 'crm/v6/';
		$url    = $base . ltrim( $module_path, '/' );

		$token = GX_ZB_OAuth::instance()->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Zoho-oauthtoken ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 20,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'gx_zb_crm_error', $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new WP_Error(
				'gx_zb_crm_error',
				__( 'Invalid JSON response from Zoho CRM.', 'gx-zoho-bookings' )
			);
		}

		// Check for API-level errors.
		if ( ! isset( $data['data'][0]['code'] ) || $data['data'][0]['code'] !== 'SUCCESS' ) {
			$message = isset( $data['message'] ) ? $data['message'] : __( 'Unknown CRM API error.', 'gx-zoho-bookings' );
			return new WP_Error( 'gx_zb_crm_error', $message );
		}

		return $data;
	}

	/**
	 * Convert a Zoho-style datetime string to ISO 8601 with timezone offset.
	 *
	 * @since 2.0.0
	 *
	 * @param string $zoho_time Datetime string in format `dd-MMM-yyyy HH:mm:ss`.
	 * @param string $timezone  Timezone string, e.g. `America/New_York`.
	 *
	 * @return string ISO 8601 formatted string.
	 */
	private function to_iso8601( $zoho_time, $timezone ) {
		$format = 'd-M-Y H:i:s';

		try {
			$tz = new DateTimeZone( $timezone );
			$dt = DateTime::createFromFormat( $format, $zoho_time, $tz );
			if ( false === $dt ) {
				// Fall back to UTC.
				$dt = DateTime::createFromFormat( $format, $zoho_time, new DateTimeZone( 'UTC' ) );
			}
			if ( false !== $dt ) {
				return $dt->format( 'c' );
			}
		} catch ( Exception $e ) {
			// Fallback outside.
		}

		// At this point, return the original string (safest fallback).
		return $zoho_time;
	}
}
