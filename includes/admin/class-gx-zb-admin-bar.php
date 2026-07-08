<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin bar node for quick access to Zoho Bookings management.
 *
 * Shows a count of today's appointments as a badge.
 * Only visible to users with manage_options when API mode and connected.
 *
 * @since 1.1.0
 */
final class GX_ZB_Admin_Bar {

	/**
	 * Singleton instance.
	 *
	 * @var GX_ZB_Admin_Bar|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return GX_ZB_Admin_Bar
	 */
	public static function instance(): GX_ZB_Admin_Bar {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_nodes' ), 80 );
	}

	/**
	 * Add admin bar nodes.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function add_nodes( $wp_admin_bar ): void {
		// Only for users who can manage options.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = GX_ZB_Settings::instance();

		// Only in API mode and when connected.
		if ( 'api' !== $settings->get( 'mode' ) || ! GX_ZB_OAuth::instance()->is_connected() ) {
			return;
		}

		// Get today's appointment count (cached in transient).
		$today_count = get_transient( 'gx_zb_today_count' );
		if ( false === $today_count ) {
			$today_count = $this->compute_today_count();
			set_transient( 'gx_zb_today_count', $today_count, 5 * MINUTE_IN_SECONDS );
		}

		$parent_title = esc_html__( 'Bookings', 'gx-zoho-bookings' );
		if ( $today_count > 0 && ! is_wp_error( $today_count ) ) {
			$parent_title .= ' <span class="gx-zb-ab-count">' . esc_html( (string) $today_count ) . '</span>';
		}

		// Parent node.
		$wp_admin_bar->add_node( array(
			'id'    => 'gx-zb',
			'title' => $parent_title,
			'href'  => admin_url( 'admin.php?page=gx-zb-dashboard' ),
			'meta'  => array( 'class' => 'gx-zb-parent' ),
		) );

		// Child: Dashboard.
		$wp_admin_bar->add_node( array(
			'parent' => 'gx-zb',
			'id'     => 'gx-zb-dashboard',
			'title'  => esc_html__( 'Dashboard', 'gx-zoho-bookings' ),
			'href'   => admin_url( 'admin.php?page=gx-zb-dashboard' ),
		) );

		// Child: Appointments.
		$wp_admin_bar->add_node( array(
			'parent' => 'gx-zb',
			'id'     => 'gx-zb-appointments',
			'title'  => esc_html__( 'Appointments', 'gx-zoho-bookings' ),
			'href'   => admin_url( 'admin.php?page=gx-zb-appointments' ),
		) );

		// Child: New Booking.
		$wp_admin_bar->add_node( array(
			'parent' => 'gx-zb',
			'id'     => 'gx-zb-new-booking',
			'title'  => esc_html__( 'New Booking', 'gx-zoho-bookings' ),
			'href'   => admin_url( 'admin.php?page=gx-zb-new-booking' ),
		) );

		// Child: Settings.
		$wp_admin_bar->add_node( array(
			'parent' => 'gx-zb',
			'id'     => 'gx-zb-settings',
			'title'  => esc_html__( 'Settings', 'gx-zoho-bookings' ),
			'href'   => admin_url( 'options-general.php?page=gx-zoho-bookings' ),
		) );
	}

	/**
	 * Compute today's appointment count from the API.
	 *
	 * @return int|WP_Error Count or error.
	 */
	private function compute_today_count() {
		$client = GX_ZB_API_Client::instance();

		$today = wp_date( 'd-M-Y' );
		$args  = array(
			'from_time' => $today,
			'to_time'   => $today,
		);

		$appointments = $client->get_appointments( $args );

		if ( is_wp_error( $appointments ) ) {
			return 0; // No count on error.
		}

		return is_array( $appointments ) ? count( $appointments ) : 0;
	}
}
