<?php
defined( 'ABSPATH' ) || exit;

/**
 * Booking Management: top-level menu, dashboard, appointments page, status actions.
 *
 * @since 1.1.0
 */
final class GX_ZB_Manage {

	/**
	 * Singleton instance.
	 *
	 * @var self
	 */
	private static $instance = null;

	/**
	 * Private constructor. Hooks are registered explicitly via register_hooks()
	 * from the plugin bootstrap — never here, or they would double-register.
	 */
	private function __construct() {}

	/**
	 * Get singleton instance.
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
	 * Register WordPress hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_gx_zb_appt_status', array( $this, 'handle_status_action' ) );
	}

	/**
	 * Add top-level menu and submenus.
	 */
	public function add_menu() {
		// Top-level menu
		add_menu_page(
			__( 'Zoho Bookings', 'gx-zoho-bookings' ),
			__( 'Zoho Bookings', 'gx-zoho-bookings' ),
			'manage_options',
			'gx-zb-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-calendar-alt',
			58
		);

		// Dashboard (same as parent)
		add_submenu_page(
			'gx-zb-dashboard',
			__( 'Dashboard', 'gx-zoho-bookings' ),
			__( 'Dashboard', 'gx-zoho-bookings' ),
			'manage_options',
			'gx-zb-dashboard',
			array( $this, 'render_dashboard' )
		);

		// Appointments
		add_submenu_page(
			'gx-zb-dashboard',
			__( 'Appointments', 'gx-zoho-bookings' ),
			__( 'Appointments', 'gx-zoho-bookings' ),
			'manage_options',
			'gx-zb-appointments',
			array( $this, 'render_appointments' )
		);

		// New Booking
		add_submenu_page(
			'gx-zb-dashboard',
			__( 'New Booking', 'gx-zoho-bookings' ),
			__( 'New Booking', 'gx-zoho-bookings' ),
			'manage_options',
			'gx-zb-new-booking',
			array( GX_ZB_Booking_Form::instance(), 'render_page' )
		);

		// Services
		add_submenu_page(
			'gx-zb-dashboard',
			__( 'Services', 'gx-zoho-bookings' ),
			__( 'Services', 'gx-zoho-bookings' ),
			'manage_options',
			'gx-zb-services',
			array( GX_ZB_Services_Admin::instance(), 'render_page' )
		);

		// Staff
		add_submenu_page(
			'gx-zb-dashboard',
			__( 'Staff', 'gx-zoho-bookings' ),
			__( 'Staff', 'gx-zoho-bookings' ),
			'manage_options',
			'gx-zb-staff',
			array( GX_ZB_Staff_Admin::instance(), 'render_page' )
		);

		// Reports — revenue & bookings dashboard (paid features).
		add_submenu_page(
			'gx-zb-dashboard',
			__( 'Reports', 'gx-zoho-bookings' ),
			__( 'Reports', 'gx-zoho-bookings' ),
			'manage_options',
			'gx-zb-reports',
			array( GX_ZB_Reports::instance(), 'render_page' )
		);

		// Settings — link straight to the existing settings page. Its URL is
		// the OAuth redirect URI, so it must not move.
		add_submenu_page(
			'gx-zb-dashboard',
			__( 'Settings', 'gx-zoho-bookings' ),
			__( 'Settings', 'gx-zoho-bookings' ),
			'manage_options',
			'options-general.php?page=gx-zoho-bookings'
		);
	}

	/**
	 * Render the Dashboard page.
	 */
	public function render_dashboard() {
		$settings = GX_ZB_Settings::instance();
		$oauth    = GX_ZB_OAuth::instance();
		$mode     = $settings->get( 'mode' );

		echo '<div class="wrap gx-zb-dashboard">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Zoho Bookings – Dashboard', 'gx-zoho-bookings' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		// Display notices from query args
		$this->maybe_show_notice();

		// Check connection status
		if ( 'api' !== $mode || ! $oauth->is_connected() ) {
			// Not connected or embed mode – show setup card
			?>
			<div class="gx-zb-setup-card">
				<h2><?php esc_html_e( 'Setup Required', 'gx-zoho-bookings' ); ?></h2>
				<p><?php esc_html_e( 'To manage bookings from WordPress, the plugin must be in API mode and connected to your Zoho account.', 'gx-zoho-bookings' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=gx-zoho-bookings' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Go to Settings & Connection', 'gx-zoho-bookings' ); ?>
				</a>
			</div>
			<?php
			echo '</div>';
			return;
		}

		// Connected and API mode – show stats
		$api           = GX_ZB_API_Client::instance();
		$today_count   = 0;
		$week_count    = 0;
		$completed_30  = 0;
		$upcoming_list = array();
		$today_data    = null;
		$week_data     = null;
		$comp_data     = null;

		// Prepare date ranges
		try {
			$tz = new DateTimeZone( wp_timezone_string() );
			$now = new DateTime( 'now', $tz );

			// Today: from_time = start of today, to_time = end of today
			$today_start = clone $now;
			$today_start->setTime( 0, 0, 0 );
			$today_end = clone $today_start;
			$today_end->setTime( 23, 59, 59 );

			// Next 7 days: from_time = today, to_time = today + 7 days
			$week_end = clone $now;
			$week_end->modify( '+7 days' )->setTime( 23, 59, 59 );

			// Completed last 30 days: from_time = today - 30 days, to_time = today
			$comp_start = clone $now;
			$comp_start->modify( '-30 days' )->setTime( 0, 0, 0 );

			// Format dates to Zoho dd-MMM-yyyy
			$date_fmt = 'd-M-Y';

			// Fetch today's appointments (only upcoming for count, status = 'upcoming')
			$today_data = $api->get_appointments( array(
				'from_time' => $today_start->format( $date_fmt ),
				'to_time'   => $today_end->format( $date_fmt ),
				'status'    => 'upcoming',
			) );
			if ( ! is_wp_error( $today_data ) && is_array( $today_data ) ) {
				$today_count = count( $today_data );
				// Keep up to 10 for quick list
				$upcoming_list = array_slice( $today_data, 0, 10 );
			}

			// Fetch next 7 days upcoming count
			$week_data = $api->get_appointments( array(
				'from_time' => $today_start->format( $date_fmt ),
				'to_time'   => $week_end->format( $date_fmt ),
				'status'    => 'upcoming',
			) );
			if ( ! is_wp_error( $week_data ) && is_array( $week_data ) ) {
				$week_count = count( $week_data );
			}

			// Completed last 30 days
			$comp_data = $api->get_appointments( array(
				'from_time' => $comp_start->format( $date_fmt ),
				'to_time'   => $today_end->format( $date_fmt ),
				'status'    => 'completed',
			) );
			if ( ! is_wp_error( $comp_data ) && is_array( $comp_data ) ) {
				$completed_30 = count( $comp_data );
			}
		} catch ( Exception $e ) {
			// Timezone or date error – leave counters 0
		}

		// If any API call failed, show an admin notice
		if ( is_wp_error( $today_data ) || is_wp_error( $week_data ) || is_wp_error( $comp_data ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			esc_html_e( 'Some appointment data could not be loaded. Check your connection.', 'gx-zoho-bookings' );
			echo '</p></div>';
		}

		// Stats cards row
		?>
		<div class="gx-zb-stats">
			<div class="gx-zb-stat">
				<span class="gx-zb-stat-number"><?php echo esc_html( $today_count ); ?></span>
				<span class="gx-zb-stat-label"><?php esc_html_e( 'Today', 'gx-zoho-bookings' ); ?></span>
			</div>
			<div class="gx-zb-stat">
				<span class="gx-zb-stat-number"><?php echo esc_html( $week_count ); ?></span>
				<span class="gx-zb-stat-label"><?php esc_html_e( 'Next 7 Days', 'gx-zoho-bookings' ); ?></span>
			</div>
			<div class="gx-zb-stat">
				<span class="gx-zb-stat-number"><?php echo esc_html( $completed_30 ); ?></span>
				<span class="gx-zb-stat-label"><?php esc_html_e( 'Completed (30d)', 'gx-zoho-bookings' ); ?></span>
			</div>
		</div>
		<?php

		// Upcoming appointments table (today’s list)
		if ( ! empty( $upcoming_list ) ) {
			echo '<h2>' . esc_html__( 'Today\'s Upcoming Appointments', 'gx-zoho-bookings' ) . '</h2>';
			echo '<table class="widefat striped gx-zb-appt-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Time', 'gx-zoho-bookings' ) . '</th>';
			echo '<th>' . esc_html__( 'Customer', 'gx-zoho-bookings' ) . '</th>';
			echo '<th>' . esc_html__( 'Service', 'gx-zoho-bookings' ) . '</th>';
			echo '<th>' . esc_html__( 'Staff', 'gx-zoho-bookings' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'gx-zoho-bookings' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'gx-zoho-bookings' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $upcoming_list as $appt ) {
				$booking_id  = isset( $appt['booking_id'] ) ? $appt['booking_id'] : '';
				$time        = isset( $appt['start_time'] ) ? gmdate( 'H:i', strtotime( $appt['start_time'] ) ) : '';
				$name        = isset( $appt['customer_name'] ) ? $appt['customer_name'] : '';
				$email       = isset( $appt['customer_email'] ) ? $appt['customer_email'] : '';
				$service     = isset( $appt['service_name'] ) ? $appt['service_name'] : '';
				$staff       = isset( $appt['staff_name'] ) ? $appt['staff_name'] : '';
				$status      = isset( $appt['booking_status'] ) ? $appt['booking_status'] : ( isset( $appt['status'] ) ? $appt['status'] : '' );
				$status_class = strtolower( $status );

				echo '<tr>';
				echo '<td data-title="' . esc_attr__( 'Time', 'gx-zoho-bookings' ) . '">' . esc_html( $time ) . '</td>';
				echo '<td data-title="' . esc_attr__( 'Customer', 'gx-zoho-bookings' ) . '">';
				echo esc_html( $name );
				if ( ! empty( $email ) ) {
					echo '<br><small>' . esc_html( $email ) . '</small>';
				}
				echo '</td>';
				echo '<td data-title="' . esc_attr__( 'Service', 'gx-zoho-bookings' ) . '">' . esc_html( $service ) . '</td>';
				echo '<td data-title="' . esc_attr__( 'Staff', 'gx-zoho-bookings' ) . '">' . esc_html( $staff ) . '</td>';
				echo '<td data-title="' . esc_attr__( 'Status', 'gx-zoho-bookings' ) . '"><span class="gx-zb-badge gx-zb-badge-' . esc_attr( $status_class ) . '">' . esc_html( $status ) . '</span></td>';
				echo '<td data-title="' . esc_attr__( 'Actions', 'gx-zoho-bookings' ) . '">';
				// Quick actions for today's list
				if ( ! empty( $booking_id ) ) {
					$nonce = wp_create_nonce( 'gx_zb_appt_status' );
					$action_url = add_query_arg( array(
						'action'     => 'gx_zb_appt_status',
						'booking_id' => $booking_id,
						'gx_action'  => 'completed',
						'_wpnonce'   => $nonce,
						'redirect_to'=> urlencode( admin_url( 'admin.php?page=gx-zb-dashboard' ) ),
					), admin_url( 'admin-post.php' ) );
					echo '<a href="' . esc_url( $action_url ) . '" class="gx-zb-action-confirm button button-small" data-confirm="' . esc_attr__( 'Mark as completed?', 'gx-zoho-bookings' ) . '">' . esc_html__( 'Complete', 'gx-zoho-bookings' ) . '</a>';
				}
				echo '</td></tr>';
			}

			echo '</tbody></table>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=gx-zb-appointments' ) ) . '" class="button">' . esc_html__( 'View All Appointments', 'gx-zoho-bookings' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'No upcoming appointments today.', 'gx-zoho-bookings' ) . '</p>';
		}

		// FUTURE (paid plan): Revenue stats, staff filters, payment summaries would go here.
		echo '</div>';
	}

	/**
	 * Render the Appointments page.
	 */
	public function render_appointments() {
		echo '<div class="wrap gx-zb-appointments">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Appointments', 'gx-zoho-bookings' ) . '</h1>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=gx-zb-new-booking' ) ) . '" class="page-title-action">' . esc_html__( 'New Booking', 'gx-zoho-bookings' ) . '</a>';
		echo '<hr class="wp-header-end">';

		$this->maybe_show_notice();

		// Check API mode and connection
		$settings = GX_ZB_Settings::instance();
		$oauth    = GX_ZB_OAuth::instance();
		if ( 'api' !== $settings->get( 'mode' ) || ! $oauth->is_connected() ) {
			echo '<div class="notice notice-warning"><p>';
			esc_html_e( 'You need API mode and an active connection to view appointments.', 'gx-zoho-bookings' );
			echo '</p></div></div>';
			return;
		}

		// Instantiate appointments table
		if ( ! class_exists( 'GX_ZB_Appointments_Table' ) ) {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Appointments table class missing. Please reinstall the plugin.', 'gx-zoho-bookings' );
			echo '</p></div></div>';
			return;
		}

		$table = new GX_ZB_Appointments_Table();
		$table->prepare_items();
		?>
		<form method="get">
			<input type="hidden" name="page" value="gx-zb-appointments" />
			<?php $table->display(); ?>
		</form>
		<?php

		echo '</div>';
	}

	/**
	 * Enqueue admin assets on manage pages.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Hook suffixes derive from the (translated) menu title, so match on
		// the stable page-slug fragment instead of hardcoded hook names.
		if ( false === strpos( (string) $hook, 'gx-zb-' ) ) {
			return;
		}

		// CSS
		wp_enqueue_style(
			'gx-zb-admin',
			GX_ZB_URL . 'assets/css/gx-zb-admin.css',
			array(),
			GX_ZB_VERSION
		);

		// JavaScript
		wp_enqueue_script(
			'gx-zb-manage',
			GX_ZB_URL . 'assets/js/gx-zb-manage.js',
			array(),
			GX_ZB_VERSION,
			true
		);

		wp_localize_script(
			'gx-zb-manage',
			'gxZbManage',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'gx_zb_manage' ),
				'confirmCancel'    => __( 'Cancel this appointment?', 'gx-zoho-bookings' ),
				'confirmComplete'  => __( 'Mark as completed?', 'gx-zoho-bookings' ),
				'confirmNoshow'    => __( 'Mark as no-show?', 'gx-zoho-bookings' ),
				'strings'          => array(
					'selectServiceFirst' => __( 'Select a service first', 'gx-zoho-bookings' ),
					'selectStaff'        => __( 'Select a staff member', 'gx-zoho-bookings' ),
					'errorLoading'       => __( 'Error loading data — try again.', 'gx-zoho-bookings' ),
					'noSlots'            => __( 'No slots available for this date.', 'gx-zoho-bookings' ),
				),
			)
		);
	}

	/**
	 * Handle status change actions (complete/cancel/noshow) from appointments or dashboard.
	 */
	public function handle_status_action() {
		// Verify nonce. List-table rows sign per-booking
		// (gx_zb_appt_status_{booking_id}); dashboard quick actions sign the
		// plain action — accept either.
		$nonce      = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		$booking_id_for_nonce = isset( $_REQUEST['booking_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['booking_id'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'gx_zb_appt_status' ) && ! wp_verify_nonce( $nonce, 'gx_zb_appt_status_' . $booking_id_for_nonce ) ) {
			wp_die( esc_html__( 'Security check failed.', 'gx-zoho-bookings' ) );
		}

		// Capability check
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'gx-zoho-bookings' ) );
		}

		$booking_id = isset( $_REQUEST['booking_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['booking_id'] ) ) : '';
		$gx_action  = isset( $_REQUEST['gx_action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['gx_action'] ) ) : '';
		$redirect   = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url( 'admin.php?page=gx-zb-appointments' );

		$valid_actions = array( 'completed', 'cancel', 'noshow' );
		if ( empty( $booking_id ) || ! in_array( $gx_action, $valid_actions, true ) ) {
			wp_die( esc_html__( 'Invalid request parameters.', 'gx-zoho-bookings' ) );
		}

		$api = GX_ZB_API_Client::instance();
		$result = $api->update_appointment_status( $booking_id, $gx_action );

		$msg_arg = is_wp_error( $result ) ? 'status_failed' : 'status_ok';
		$redirect = add_query_arg( 'gx_zb_msg', $msg_arg, $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Display admin notice if gx_zb_msg parameter is present.
	 *
	 * Public so the Services and Staff pages can reuse it.
	 */
	public function maybe_show_notice() {
		if ( empty( $_GET['gx_zb_msg'] ) ) {
			return;
		}

		$msg  = sanitize_text_field( wp_unslash( $_GET['gx_zb_msg'] ) );
		$detail = isset( $_GET['gx_zb_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['gx_zb_detail'] ) ) : '';

		$class = 'notice is-dismissible';
		$message = '';

		switch ( $msg ) {
			case 'status_ok':
				$class .= ' notice-success';
				$message = __( 'Appointment status updated successfully.', 'gx-zoho-bookings' );
				break;
			case 'status_failed':
				$class .= ' notice-error';
				$message = __( 'Failed to update appointment status.', 'gx-zoho-bookings' );
				break;
			case 'created':
				$class .= ' notice-success';
				$message = __( 'Appointment created successfully.', 'gx-zoho-bookings' );
				break;
			case 'create_failed':
				$class .= ' notice-error';
				$message = __( 'Appointment creation failed.', 'gx-zoho-bookings' );
				if ( ! empty( $detail ) ) {
					$message .= ' ' . __( 'Error:', 'gx-zoho-bookings' ) . ' ' . esc_html( $detail );
				}
				break;
			case 'rescheduled':
				$class .= ' notice-success';
				$message = __( 'Appointment rescheduled successfully.', 'gx-zoho-bookings' );
				break;
			case 'reschedule_failed':
				$class .= ' notice-error';
				$message = __( 'Rescheduling failed.', 'gx-zoho-bookings' );
				break;
			case 'service_saved':
				$class  .= ' notice-success';
				$message = __( 'Service saved.', 'gx-zoho-bookings' );
				break;
			case 'service_save_failed':
				$class  .= ' notice-error';
				$message = __( 'The service could not be saved.', 'gx-zoho-bookings' );
				break;
			case 'service_deleted':
				$class  .= ' notice-success';
				$message = __( 'Service deleted.', 'gx-zoho-bookings' );
				break;
			case 'service_delete_failed':
				$class  .= ' notice-error';
				$message = __( 'The service could not be deleted.', 'gx-zoho-bookings' );
				break;
			case 'workspace_created':
				$class  .= ' notice-success';
				$message = __( 'Workspace created.', 'gx-zoho-bookings' );
				break;
			case 'workspace_create_failed':
				$class  .= ' notice-error';
				$message = __( 'The workspace could not be created.', 'gx-zoho-bookings' );
				break;
			case 'staff_added':
				$class  .= ' notice-success';
				$message = __( 'Staff member added.', 'gx-zoho-bookings' );
				break;
			case 'staff_add_failed':
				$class  .= ' notice-error';
				$message = __( 'The staff member could not be added.', 'gx-zoho-bookings' );
				break;
			case 'staff_meta_saved':
				$class  .= ' notice-success';
				$message = __( 'Video call link saved.', 'gx-zoho-bookings' );
				break;
			case 'staff_meta_failed':
				$class  .= ' notice-error';
				$message = __( 'The video call link could not be saved.', 'gx-zoho-bookings' );
				break;
			case 'staff_hidden':
				$class  .= ' notice-success';
				$message = __( 'Staff member removed from booking on this site. Delete the account itself in the Zoho Bookings admin if needed.', 'gx-zoho-bookings' );
				break;
			case 'staff_restored':
				$class  .= ' notice-success';
				$message = __( 'Staff member restored and bookable again.', 'gx-zoho-bookings' );
				break;
			case 'staff_toggle_failed':
				$class  .= ' notice-error';
				$message = __( 'The staff member could not be updated.', 'gx-zoho-bookings' );
				break;
			default:
				return; // unknown, ignore
		}

		// Append the API error detail on v1.3 failure notices.
		$detailed = array( 'service_save_failed', 'service_delete_failed', 'workspace_create_failed', 'staff_add_failed', 'reschedule_failed', 'status_failed' );
		if ( ! empty( $detail ) && in_array( $msg, $detailed, true ) ) {
			$message .= ' ' . __( 'Error:', 'gx-zoho-bookings' ) . ' ' . $detail;
		}

		printf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}
}
// No closing PHP tag – intentional.