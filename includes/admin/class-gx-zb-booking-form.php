<?php
defined( 'ABSPATH' ) || exit;

/**
 * GX_ZB_Booking_Form class
 *
 * Handles the New Booking and Reschedule admin pages, including AJAX endpoints
 * for dynamic service/staff/slot loading and admin-post handlers for submission.
 *
 * @since 1.1.0
 */
final class GX_ZB_Booking_Form {

	/**
	 * Singleton instance.
	 *
	 * @var GX_ZB_Booking_Form|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return GX_ZB_Booking_Form
	 */
	public static function instance(): GX_ZB_Booking_Form {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Registers hooks for AJAX and admin_post actions.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_gx_zb_get_services', array( $this, 'ajax_get_services' ) );
		add_action( 'wp_ajax_gx_zb_get_staff', array( $this, 'ajax_get_staff' ) );
		add_action( 'wp_ajax_gx_zb_get_slots', array( $this, 'ajax_get_slots' ) );

		add_action( 'admin_post_gx_zb_create_booking', array( $this, 'handle_create_booking' ) );
		add_action( 'admin_post_gx_zb_reschedule', array( $this, 'handle_reschedule' ) );
	}

	/**
	 * Renders the New Booking / Reschedule admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gx-zoho-bookings' ) );
		}

		// Booking IDs are opaque Zoho strings — never cast to int.
		$is_reschedule = isset( $_GET['reschedule'] );
		$booking_id    = $is_reschedule ? sanitize_text_field( wp_unslash( $_GET['reschedule'] ) ) : '';
		$service_id    = $is_reschedule ? sanitize_text_field( wp_unslash( $_GET['service_id'] ?? '' ) ) : '';
		$staff_id_val  = $is_reschedule ? sanitize_text_field( wp_unslash( $_GET['staff_id'] ?? '' ) ) : '';

		$api_client = GX_ZB_API_Client::instance();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $is_reschedule ? __( 'Reschedule Booking', 'gx-zoho-bookings' ) : __( 'New Booking', 'gx-zoho-bookings' ) ); ?></h1>

			<div class="gx-zb-booking-form">
				<form method="post" action="<?php echo esc_url( admin_url( $is_reschedule ? 'admin-post.php?action=gx_zb_reschedule' : 'admin-post.php?action=gx_zb_create_booking' ) ); ?>">
					<?php
					if ( $is_reschedule ) {
						wp_nonce_field( 'gx_zb_reschedule' );
						if ( $booking_id ) {
							echo '<input type="hidden" name="booking_id" value="' . esc_attr( $booking_id ) . '" />';
						}
						if ( $service_id ) {
							// id gx-zb-service so the slot-loader JS can read the
							// service value in reschedule mode too.
							echo '<input type="hidden" id="gx-zb-service" name="service_id" value="' . esc_attr( $service_id ) . '" />';
						}
					} else {
						wp_nonce_field( 'gx_zb_create_booking' );
					}
					?>

					<?php if ( ! $is_reschedule ) : ?>
						<!-- Service selection (only for new booking) -->
						<div class="gx-zb-field">
							<label for="gx-zb-service"><?php esc_html_e( 'Service', 'gx-zoho-bookings' ); ?>
								<select id="gx-zb-service" name="service_id" required>
									<option value=""><?php esc_html_e( 'Select a service', 'gx-zoho-bookings' ); ?></option>
									<?php
									$services = $api_client->get_services();
									if ( is_wp_error( $services ) ) {
										// show an option indicating error for admins
										echo '<option value="" disabled>' . esc_html__( 'Unable to load services at this moment.', 'gx-zoho-bookings' ) . '</option>';
									} else {
										foreach ( $services as $service ) {
											$service_id_attr   = $service['id'] ?? '';
											$service_name      = $service['name'] ?? '';
											$service_duration  = $service['duration'] ?? '';
											$duration_label    = $service_duration ? ' (' . absint( $service_duration ) . ' min)' : '';
											printf(
												'<option value="%s">%s%s</option>',
												esc_attr( $service_id_attr ),
												esc_html( $service_name ),
												esc_html( $duration_label )
											);
										}
									}
									?>
								</select>
							</label>
						</div>
					<?php endif; ?>

					<!-- Staff selection -->
					<div class="gx-zb-field">
						<label for="gx-zb-staff"><?php esc_html_e( 'Staff Member', 'gx-zoho-bookings' ); ?>
							<select id="gx-zb-staff" name="staff_id" required>
								<option value=""><?php esc_html_e( 'Select a staff member', 'gx-zoho-bookings' ); ?></option>
								<?php
								if ( $is_reschedule && $service_id ) {
									$staff_list = $api_client->get_staff( $service_id );
									if ( ! is_wp_error( $staff_list ) ) {
										foreach ( $staff_list as $staff ) {
											$selected = ( $staff_id_val === $staff['id'] ) ? ' selected' : '';
											printf(
												'<option value="%s"%s>%s</option>',
												esc_attr( $staff['id'] ),
												$selected,
												esc_html( $staff['name'] ?? '' )
											);
										}
									}
								}
								?>
							</select>
						</label>
					</div>

					<!-- Date field -->
					<div class="gx-zb-field">
						<label for="gx-zb-date"><?php esc_html_e( 'Date', 'gx-zoho-bookings' ); ?>
							<input type="date" id="gx-zb-date" name="date" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required />
						</label>
						<p class="description"><?php printf( esc_html__( 'Timezone: %s', 'gx-zoho-bookings' ), esc_html( wp_timezone_string() ) ); ?></p>
					</div>

					<!-- Slot buttons grid (populated via JS) -->
					<div class="gx-zb-field">
						<label><?php esc_html_e( 'Available Slots', 'gx-zoho-bookings' ); ?></label>
						<div id="gx-zb-slots" class="gx-zb-slots">
							<span class="gx-zb-slots-placeholder"><?php esc_html_e( 'Select service, staff and date to see available slots.', 'gx-zoho-bookings' ); ?></span>
						</div>
						<input type="hidden" id="gx-zb-slot" name="slot" value="" />
					</div>

					<?php if ( ! $is_reschedule ) : ?>
						<!-- Customer details (new booking only) -->
						<fieldset>
							<legend><?php esc_html_e( 'Customer Details', 'gx-zoho-bookings' ); ?></legend>
							<div class="gx-zb-field">
								<label for="gx-zb-customer-name"><?php esc_html_e( 'Name', 'gx-zoho-bookings' ); ?>
									<input type="text" id="gx-zb-customer-name" name="customer_name" required />
								</label>
							</div>
							<div class="gx-zb-field">
								<label for="gx-zb-customer-email"><?php esc_html_e( 'Email', 'gx-zoho-bookings' ); ?>
									<input type="email" id="gx-zb-customer-email" name="customer_email" required />
								</label>
							</div>
							<div class="gx-zb-field">
								<label for="gx-zb-customer-phone"><?php esc_html_e( 'Phone', 'gx-zoho-bookings' ); ?>
									<input type="tel" id="gx-zb-customer-phone" name="customer_phone" />
								</label>
							</div>
							<div class="gx-zb-field">
								<label for="gx-zb-notes"><?php esc_html_e( 'Notes', 'gx-zoho-bookings' ); ?>
									<textarea id="gx-zb-notes" name="notes"></textarea>
								</label>
							</div>
						</fieldset>
					<?php endif; ?>

					<div class="gx-zb-form-actions">
						<input type="submit" class="button button-primary" id="gx-zb-submit" value="<?php echo esc_attr( $is_reschedule ? __( 'Reschedule', 'gx-zoho-bookings' ) : __( 'Create Booking', 'gx-zoho-bookings' ) ); ?>" disabled />
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=gx-zb-appointments' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'gx-zoho-bookings' ); ?></a>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Get services.
	 *
	 * @return void
	 */
	public function ajax_get_services(): void {
		check_ajax_referer( 'gx_zb_manage' ); // JS sends the default _ajax_nonce field.

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gx-zoho-bookings' ) ), 403 );
		}

		$workspace_id = isset( $_POST['workspace_id'] ) ? sanitize_text_field( wp_unslash( $_POST['workspace_id'] ) ) : '';

		$api_client = GX_ZB_API_Client::instance();
		$services   = $api_client->get_services( $workspace_id );

		if ( is_wp_error( $services ) ) {
			wp_send_json_error(
				array( 'message' => $services->get_error_message() ),
				500
			);
		}

		$output = array();
		foreach ( $services as $service ) {
			$output[] = array(
				'id'       => $service['id'] ?? '',
				'name'     => $service['name'] ?? '',
				'duration' => $service['duration'] ?? 0,
			);
		}

		wp_send_json_success( $output );
	}

	/**
	 * AJAX handler: Get staff for a service.
	 *
	 * @return void
	 */
	public function ajax_get_staff(): void {
		check_ajax_referer( 'gx_zb_manage' ); // JS sends the default _ajax_nonce field.

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gx-zoho-bookings' ) ), 403 );
		}

		$service_id = isset( $_POST['service_id'] ) ? sanitize_text_field( wp_unslash( $_POST['service_id'] ) ) : '';
		if ( ! $service_id ) {
			wp_send_json_error( array( 'message' => __( 'Service ID is required.', 'gx-zoho-bookings' ) ), 400 );
		}

		$api_client = GX_ZB_API_Client::instance();
		$staff      = $api_client->get_staff( $service_id );

		if ( is_wp_error( $staff ) ) {
			wp_send_json_error(
				array( 'message' => $staff->get_error_message() ),
				500
			);
		}

		// Staff removed on this site are not bookable.
		if ( class_exists( 'GX_ZB_Staff_Meta' ) ) {
			$staff = GX_ZB_Staff_Meta::filter_visible( (array) $staff );
		}

		$output = array();
		foreach ( $staff as $member ) {
			$output[] = array(
				'id'   => $member['id'] ?? '',
				'name' => $member['name'] ?? '',
			);
		}

		wp_send_json_success( $output );
	}

	/**
	 * AJAX handler: Get available slots.
	 *
	 * @return void
	 */
	public function ajax_get_slots(): void {
		check_ajax_referer( 'gx_zb_manage' ); // JS sends the default _ajax_nonce field.

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gx-zoho-bookings' ) ), 403 );
		}

		$service_id = isset( $_POST['service_id'] ) ? sanitize_text_field( wp_unslash( $_POST['service_id'] ) ) : '';
		$staff_id   = isset( $_POST['staff_id'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_id'] ) ) : '';
		$date       = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : ''; // Y-m-d

		if ( ! $service_id || ! $staff_id || ! $date ) {
			wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'gx-zoho-bookings' ) ), 400 );
		}

		// Convert to Zoho date format: dd-MMM-yyyy
		try {
			$date_obj = new DateTime( $date );
			$zoho_date = $date_obj->format( 'd-M-Y' );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date format.', 'gx-zoho-bookings' ) ), 400 );
		}

		$api_client = GX_ZB_API_Client::instance();
		$slots      = $api_client->get_available_slots( $service_id, $staff_id, $zoho_date );

		if ( is_wp_error( $slots ) ) {
			wp_send_json_error(
				array( 'message' => $slots->get_error_message() ),
				500
			);
		}

		wp_send_json_success( $slots );
	}

	/**
	 * Admin-post handler: Create a new booking.
	 *
	 * @return void
	 */
	public function handle_create_booking(): void {
		check_admin_referer( 'gx_zb_create_booking' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'gx-zoho-bookings' ) );
		}

		$service_id   = isset( $_POST['service_id'] ) ? sanitize_text_field( wp_unslash( $_POST['service_id'] ) ) : '';
		$staff_id     = isset( $_POST['staff_id'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_id'] ) ) : '';
		$date         = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : ''; // Y-m-d
		$slot         = isset( $_POST['slot'] ) ? sanitize_text_field( wp_unslash( $_POST['slot'] ) ) : '';
		$customer_name  = isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '';
		$customer_email = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
		$customer_phone = isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) ) : '';
		$notes        = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		// Basic validations
		if ( ! $service_id || ! $staff_id || ! $date || ! $slot || ! $customer_name || ! is_email( $customer_email ) ) {
			$redirect = add_query_arg(
				array(
					'page'       => 'gx-zb-appointments',
					'gx_zb_msg'  => 'create_failed',
					'gx_zb_detail' => rawurlencode( __( 'Required fields missing or invalid email.', 'gx-zoho-bookings' ) ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		// Parse slot time and combine with date to Zoho format
		try {
			$date_obj = new DateTime( $date );
			// Zoho returns 12-hour slots on most workspaces, 24-hour on some.
			$time_obj = DateTime::createFromFormat( 'h:i A', $slot );
			if ( ! $time_obj ) {
				$time_obj = DateTime::createFromFormat( 'H:i', $slot );
			}
			if ( ! $time_obj ) {
				throw new Exception( 'Invalid slot format' );
			}
			$date_obj->setTime( (int) $time_obj->format( 'H' ), (int) $time_obj->format( 'i' ), 0 );
			$from_time = $date_obj->format( 'd-M-Y H:i:s' );
		} catch ( Exception $e ) {
			$redirect = add_query_arg(
				array(
					'page'       => 'gx-zb-appointments',
					'gx_zb_msg'  => 'create_failed',
					'gx_zb_detail' => rawurlencode( __( 'Invalid date or time slot.', 'gx-zoho-bookings' ) ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$args = array(
			'service_id'      => $service_id,
			'staff_id'        => $staff_id,
			'from_time'       => $from_time,
			'timezone'        => wp_timezone_string(),
			// Array on purpose — the API client JSON-encodes customer_details
			// into the form field; pre-encoding here would double-encode.
			'customer_details' => array(
				'name'         => $customer_name,
				'email'        => $customer_email,
				'phone_number' => $customer_phone,
			),
		);

		if ( $notes ) {
			$args['notes'] = $notes;
		}

		$api_client = GX_ZB_API_Client::instance();
		$result     = $api_client->create_appointment( $args );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array(
					'page'       => 'gx-zb-appointments',
					'gx_zb_msg'  => 'create_failed',
					'gx_zb_detail' => rawurlencode( $result->get_error_message() ),
				),
				admin_url( 'admin.php' )
			);
		} else {
			// Flush cache after write
			$api_client->flush_cache();
			$redirect = add_query_arg(
				array(
					'page'      => 'gx-zb-appointments',
					'gx_zb_msg' => 'created',
				),
				admin_url( 'admin.php' )
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Admin-post handler: Reschedule an appointment.
	 *
	 * @return void
	 */
	public function handle_reschedule(): void {
		check_admin_referer( 'gx_zb_reschedule' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'gx-zoho-bookings' ) );
		}

		$booking_id = isset( $_POST['booking_id'] ) ? sanitize_text_field( wp_unslash( $_POST['booking_id'] ) ) : '';
		$staff_id   = isset( $_POST['staff_id'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_id'] ) ) : '';
		$date       = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : ''; // Y-m-d
		$slot       = isset( $_POST['slot'] ) ? sanitize_text_field( wp_unslash( $_POST['slot'] ) ) : '';

		if ( ! $booking_id || ! $date || ! $slot ) {
			$redirect = add_query_arg(
				array(
					'page'       => 'gx-zb-appointments',
					'gx_zb_msg'  => 'reschedule_failed',
					'gx_zb_detail' => rawurlencode( __( 'Missing required fields.', 'gx-zoho-bookings' ) ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		// Combine date and slot into Zoho format
		try {
			$date_obj = new DateTime( $date );
			// Zoho returns 12-hour slots on most workspaces, 24-hour on some.
			$time_obj = DateTime::createFromFormat( 'h:i A', $slot );
			if ( ! $time_obj ) {
				$time_obj = DateTime::createFromFormat( 'H:i', $slot );
			}
			if ( ! $time_obj ) {
				throw new Exception( 'Invalid slot format' );
			}
			$date_obj->setTime( (int) $time_obj->format( 'H' ), (int) $time_obj->format( 'i' ), 0 );
			$start_time = $date_obj->format( 'd-M-Y H:i:s' );
		} catch ( Exception $e ) {
			$redirect = add_query_arg(
				array(
					'page'       => 'gx-zb-appointments',
					'gx_zb_msg'  => 'reschedule_failed',
					'gx_zb_detail' => rawurlencode( __( 'Invalid date or time slot.', 'gx-zoho-bookings' ) ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$api_client = GX_ZB_API_Client::instance();
		$result     = $api_client->reschedule_appointment( $booking_id, $start_time, $staff_id );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array(
					'page'       => 'gx-zb-appointments',
					'gx_zb_msg'  => 'reschedule_failed',
					'gx_zb_detail' => rawurlencode( $result->get_error_message() ),
				),
				admin_url( 'admin.php' )
			);
		} else {
			// Flush cache after write
			$api_client->flush_cache();
			$redirect = add_query_arg(
				array(
					'page'      => 'gx-zb-appointments',
					'gx_zb_msg' => 'rescheduled',
				),
				admin_url( 'admin.php' )
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}