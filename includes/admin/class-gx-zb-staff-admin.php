<?php
defined( 'ABSPATH' ) || exit;

/**
 * Staff management admin page.
 *
 * @package GX_Zoho_Bookings
 * @since 1.3.0
 */
final class GX_ZB_Staff_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return GX_ZB_Staff_Admin
	 */
	public static function instance() {
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
	 * Register hooks for staff admin actions.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_gx_zb_staff_add', array( $this, 'handle_add_staff' ) );
		add_action( 'admin_post_gx_zb_staff_meta', array( $this, 'handle_staff_meta' ) );
		add_action( 'admin_post_gx_zb_staff_toggle', array( $this, 'handle_staff_toggle' ) );
	}

	/**
	 * Render the staff management page (list + add form).
	 *
	 * @return void
	 */
	public function render_page() {
		// Display any status messages from redirects.
		if ( class_exists( 'GX_ZB_Manage' ) ) {
			GX_ZB_Manage::instance()->maybe_show_notice();
		}

		// Check access.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = GX_ZB_Settings::instance();
		$oauth    = GX_ZB_OAuth::instance();

		// API mode must be enabled and connected.
		if ( 'api' !== $settings->get( 'mode' ) || ! $oauth->is_connected() ) {
			$this->render_setup_card();
			return;
		}

		// Fetch staff data.
		$staff_list = GX_ZB_API_Client::instance()->get_staff();
		?>
		<div class="wrap gx-zb-page gx-zb-staff-page">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Staff', 'gx-zoho-bookings' ); ?></h1>
			<hr class="wp-header-end">

			<?php
			// Info notice about API limitations.
			?>
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: Zoho Bookings admin URL */
						esc_html__( 'Removing a staff member here only hides them from booking on this website. Editing or deleting the Zoho account itself still happens in the %s.', 'gx-zoho-bookings' ),
						'<a href="' . esc_url( 'https://bookings.zoho.com' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Zoho Bookings admin', 'gx-zoho-bookings' ) . '</a>'
					);
					?>
				</p>
			</div>

			<?php
			// Staff list.
			if ( is_wp_error( $staff_list ) ) {
				?>
				<div class="notice notice-error inline">
					<p>
						<?php
						printf(
							/* translators: %s: error message */
							esc_html__( 'Could not fetch staff list: %s', 'gx-zoho-bookings' ),
							esc_html( $staff_list->get_error_message() )
						);
						?>
					</p>
				</div>
				<?php
			} elseif ( empty( $staff_list ) ) {
				?>
				<p><?php esc_html_e( 'No staff members found.', 'gx-zoho-bookings' ); ?></p>
				<?php
			} else {
				?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'gx-zoho-bookings' ); ?></th>
							<th><?php esc_html_e( 'Email', 'gx-zoho-bookings' ); ?></th>
							<th><?php esc_html_e( 'Designation', 'gx-zoho-bookings' ); ?></th>
							<th><?php esc_html_e( 'Role', 'gx-zoho-bookings' ); ?></th>
							<th><?php esc_html_e( 'Video call link', 'gx-zoho-bookings' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'gx-zoho-bookings' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $staff_list as $staff ) :
							$sid       = isset( $staff['id'] ) ? (string) $staff['id'] : '';
							$is_hidden = ( '' !== $sid ) ? GX_ZB_Staff_Meta::is_hidden( $sid ) : false;
							?>
						<tr<?php echo $is_hidden ? ' style="opacity:.55"' : ''; ?>>
							<td>
								<?php echo esc_html( isset( $staff['name'] ) ? $staff['name'] : '—' ); ?>
								<?php if ( $is_hidden ) : ?>
									<em><?php esc_html_e( '— removed', 'gx-zoho-bookings' ); ?></em>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( isset( $staff['email'] ) ? $staff['email'] : '—' ); ?></td>
							<td><?php echo esc_html( isset( $staff['designation'] ) ? $staff['designation'] : '—' ); ?></td>
							<td><?php echo esc_html( isset( $staff['role'] ) ? $staff['role'] : '—' ); ?></td>
							<td>
								<?php if ( '' === $sid ) : ?>
									—
								<?php else : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( 'gx_zb_staff_meta' ); ?>
										<input type="hidden" name="action" value="gx_zb_staff_meta">
										<input type="hidden" name="staff_id" value="<?php echo esc_attr( $sid ); ?>">
										<input type="url" name="gx_zb_video_url" value="<?php echo esc_attr( GX_ZB_Staff_Meta::video_url( $sid ) ); ?>" class="regular-text" placeholder="https://meet.google.com/…">
										<?php echo get_submit_button( __( 'Save', 'gx-zoho-bookings' ), 'small', 'submit', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</form>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' === $sid ) : ?>
									—
								<?php else : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( 'gx_zb_staff_toggle' ); ?>
										<input type="hidden" name="action" value="gx_zb_staff_toggle">
										<input type="hidden" name="staff_id" value="<?php echo esc_attr( $sid ); ?>">
										<?php if ( $is_hidden ) : ?>
											<input type="hidden" name="gx_zb_staff_op" value="restore">
											<?php echo get_submit_button( __( 'Restore', 'gx-zoho-bookings' ), 'small', 'submit', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php else : ?>
											<input type="hidden" name="gx_zb_staff_op" value="hide">
											<?php echo get_submit_button( __( 'Remove', 'gx-zoho-bookings' ), 'small button-link-delete', 'submit', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php endif; ?>
									</form>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
			}
			?>

			<h2><?php esc_html_e( 'Add New Staff', 'gx-zoho-bookings' ); ?></h2>
			<p><em><?php esc_html_e( 'Note: The free plan allows only one staff member. API errors will be shown if the limit is exceeded.', 'gx-zoho-bookings' ); ?></em></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gx-zb-booking-form">
				<?php wp_nonce_field( 'gx_zb_staff_add', 'gx_zb_staff_nonce' ); ?>
				<input type="hidden" name="action" value="gx_zb_staff_add">

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="gx-zb-staff-name"><?php esc_html_e( 'Name', 'gx-zoho-bookings' ); ?> <span class="required">*</span></label>
						</th>
						<td>
							<input type="text" id="gx-zb-staff-name" name="gx_zb_staff_name" required class="regular-text">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gx-zb-staff-email"><?php esc_html_e( 'Email', 'gx-zoho-bookings' ); ?> <span class="required">*</span></label>
						</th>
						<td>
							<input type="email" id="gx-zb-staff-email" name="gx_zb_staff_email" required class="regular-text">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gx-zb-staff-phone"><?php esc_html_e( 'Phone', 'gx-zoho-bookings' ); ?></label>
						</th>
						<td>
							<input type="text" id="gx-zb-staff-phone" name="gx_zb_staff_phone" class="regular-text">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gx-zb-staff-designation"><?php esc_html_e( 'Designation', 'gx-zoho-bookings' ); ?></label>
						</th>
						<td>
							<input type="text" id="gx-zb-staff-designation" name="gx_zb_staff_designation" class="regular-text">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gx-zb-staff-role"><?php esc_html_e( 'Role', 'gx-zoho-bookings' ); ?></label>
						</th>
						<td>
							<select id="gx-zb-staff-role" name="gx_zb_staff_role">
								<option value="Staff"><?php esc_html_e( 'Staff', 'gx-zoho-bookings' ); ?></option>
								<option value="Manager"><?php esc_html_e( 'Manager', 'gx-zoho-bookings' ); ?></option>
								<option value="Admin"><?php esc_html_e( 'Admin', 'gx-zoho-bookings' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Assigned Services', 'gx-zoho-bookings' ); ?></th>
						<td>
							<?php
							$services = GX_ZB_API_Client::instance()->get_services();
							if ( ! is_wp_error( $services ) && ! empty( $services ) ) {
								foreach ( $services as $service ) {
									$service_id = isset( $service['id'] ) ? esc_attr( $service['id'] ) : '';
									$service_name = isset( $service['name'] ) ? esc_html( $service['name'] ) : '';
									?>
									<label style="display:block; margin-bottom:4px;">
										<input type="checkbox" name="gx_zb_assigned_services[]" value="<?php echo $service_id; ?>">
										<?php echo $service_name; ?>
									</label>
									<?php
								}
							} else {
								?>
								<p><?php esc_html_e( 'No services available.', 'gx-zoho-bookings' ); ?></p>
								<?php
							}
							?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Add Staff', 'gx-zoho-bookings' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles the add staff form submission.
	 *
	 * @return void
	 */
	public function handle_add_staff() {
		// Capability check.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gx-zoho-bookings' ) );
		}

		// Nonce verification.
		check_admin_referer( 'gx_zb_staff_add', 'gx_zb_staff_nonce' );

		// Sanitize inputs.
		$name         = isset( $_POST['gx_zb_staff_name'] ) ? sanitize_text_field( wp_unslash( $_POST['gx_zb_staff_name'] ) ) : '';
		$email        = isset( $_POST['gx_zb_staff_email'] ) ? sanitize_email( wp_unslash( $_POST['gx_zb_staff_email'] ) ) : '';
		$phone        = isset( $_POST['gx_zb_staff_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['gx_zb_staff_phone'] ) ) : '';
		$designation  = isset( $_POST['gx_zb_staff_designation'] ) ? sanitize_text_field( wp_unslash( $_POST['gx_zb_staff_designation'] ) ) : '';
		$role         = isset( $_POST['gx_zb_staff_role'] ) ? sanitize_text_field( wp_unslash( $_POST['gx_zb_staff_role'] ) ) : 'Staff';
		$assigned     = isset( $_POST['gx_zb_assigned_services'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['gx_zb_assigned_services'] ) ) : array();

		// Validate required fields.
		$errors = array();
		if ( empty( $name ) ) {
			$errors[] = __( 'Name is required.', 'gx-zoho-bookings' );
		}
		if ( empty( $email ) || ! is_email( $email ) ) {
			$errors[] = __( 'A valid email address is required.', 'gx-zoho-bookings' );
		}
		$allowed_roles = array( 'Staff', 'Manager', 'Admin' );
		if ( ! in_array( $role, $allowed_roles, true ) ) {
			$role = 'Staff';
		}

		if ( ! empty( $errors ) ) {
			$redirect = add_query_arg(
				array(
					'page'              => 'gx-zb-staff',
					'gx_zb_msg'         => 'staff_add_failed',
					'gx_zb_detail'      => rawurlencode( implode( ' ', $errors ) ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		// Build staff object for API.
		$staff_data = array(
			'name'              => $name,
			'email'             => $email,
			'phone'             => $phone,
			'designation'       => $designation,
			'role'              => $role,
			'assigned_services' => $assigned,
		);

		$result = GX_ZB_API_Client::instance()->add_staff( $staff_data );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array(
					'page'              => 'gx-zb-staff',
					'gx_zb_msg'         => 'staff_add_failed',
					'gx_zb_detail'      => rawurlencode( $result->get_error_message() ),
				),
				admin_url( 'admin.php' )
			);
		} else {
			$redirect = add_query_arg(
				array(
					'page'      => 'gx-zb-staff',
					'gx_zb_msg' => 'staff_added',
				),
				admin_url( 'admin.php' )
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Saves a staff member's video-conference URL.
	 *
	 * @return void
	 */
	public function handle_staff_meta() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gx-zoho-bookings' ) );
		}
		check_admin_referer( 'gx_zb_staff_meta' );

		$staff_id = isset( $_POST['staff_id'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_id'] ) ) : '';
		$url      = isset( $_POST['gx_zb_video_url'] ) ? esc_url_raw( wp_unslash( $_POST['gx_zb_video_url'] ) ) : '';

		if ( '' === $staff_id ) {
			$msg = 'staff_meta_failed';
		} else {
			GX_ZB_Staff_Meta::set_video_url( $staff_id, $url );
			$msg = 'staff_meta_saved';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'gx-zb-staff',
					'gx_zb_msg' => $msg,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Hides a staff member from booking on this site, or restores them.
	 *
	 * @return void
	 */
	public function handle_staff_toggle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gx-zoho-bookings' ) );
		}
		check_admin_referer( 'gx_zb_staff_toggle' );

		$staff_id = isset( $_POST['staff_id'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_id'] ) ) : '';
		$op       = isset( $_POST['gx_zb_staff_op'] ) ? sanitize_key( wp_unslash( $_POST['gx_zb_staff_op'] ) ) : '';

		if ( '' === $staff_id || ! in_array( $op, array( 'hide', 'restore' ), true ) ) {
			$msg = 'staff_toggle_failed';
		} elseif ( 'hide' === $op ) {
			GX_ZB_Staff_Meta::hide( $staff_id );
			$msg = 'staff_hidden';
		} else {
			GX_ZB_Staff_Meta::unhide( $staff_id );
			$msg = 'staff_restored';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'gx-zb-staff',
					'gx_zb_msg' => $msg,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renders a setup card when API mode is off or not connected.
	 *
	 * @return void
	 */
	private function render_setup_card() {
		?>
		<div class="wrap gx-zb-page">
			<h1><?php echo esc_html__( 'Staff', 'gx-zoho-bookings' ); ?></h1>
			<div class="gx-zb-status-card is-disconnected" style="padding: 20px; border-radius: 8px; border: 1px solid #ccd0d4; background: #fff;">
				<p>
					<?php esc_html_e( 'Staff management requires API mode to be enabled and a connection to your Zoho Bookings account.', 'gx-zoho-bookings' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=gx-zoho-bookings' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Go to Settings', 'gx-zoho-bookings' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}
}