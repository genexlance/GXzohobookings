<?php
defined( 'ABSPATH' ) || exit;

final class GX_ZB_Services_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function register_hooks() {
		add_action( 'admin_post_gx_zb_service_save', [ $this, 'handle_save_service' ] );
		add_action( 'admin_post_gx_zb_service_delete', [ $this, 'handle_delete_service' ] );
		add_action( 'admin_post_gx_zb_service_status', [ $this, 'handle_toggle_status' ] );
		add_action( 'admin_post_gx_zb_workspace_create', [ $this, 'handle_create_workspace' ] );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gx-zoho-bookings' ) );
		}

		$settings = GX_ZB_Settings::instance();
		if ( 'api' !== $settings->get( 'mode' ) || ! GX_ZB_OAuth::instance()->is_connected() ) {
			$this->render_setup_prompt();
			return;
		}

		if ( class_exists( 'GX_ZB_Manage' ) && method_exists( 'GX_ZB_Manage', 'instance' ) ) {
			GX_ZB_Manage::instance()->maybe_show_notice();
		}

		$view = isset( $_GET['view'] ) ? sanitize_text_field( $_GET['view'] ) : 'list';
		$allowed_views = [ 'list', 'new', 'edit' ];
		if ( ! in_array( $view, $allowed_views, true ) ) {
			$view = 'list';
		}

		$api_client   = GX_ZB_API_Client::instance();
		$workspace_id = isset( $_GET['workspace'] ) ? sanitize_text_field( $_GET['workspace'] ) : '';

		if ( 'edit' === $view ) {
			$service_id = isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : '';
			if ( empty( $service_id ) ) {
				wp_redirect( add_query_arg( [ 'page' => 'gx-zb-services', 'gx_zb_msg' => 'service_not_found' ], admin_url( 'admin.php' ) ) );
				exit;
			}
			$services = $api_client->get_services( $workspace_id );
			if ( is_wp_error( $services ) ) {
				$this->render_error_notice( $services );
				$view = 'list';
			} else {
				$found = false;
				foreach ( $services as $s ) {
					if ( ( $s['id'] ?? '' ) === $service_id || ( $s['service_id'] ?? '' ) === $service_id ) {
						$found = $s;
						break;
					}
				}
				if ( ! $found ) {
					wp_redirect( add_query_arg( [ 'page' => 'gx-zb-services', 'gx_zb_msg' => 'service_not_found' ], admin_url( 'admin.php' ) ) );
					exit;
				}
			}
		}

		switch ( $view ) {
			case 'new':
				$this->render_new_form( $workspace_id );
				break;
			case 'edit':
				$this->render_edit_form( $workspace_id, $found ?? [] );
				break;
			default:
				$this->render_list( $api_client, $workspace_id );
				break;
		}
	}

	private function render_list( $api_client, $workspace_id ) {
		$workspaces = $api_client->get_workspaces();
		$workspace_list = is_wp_error( $workspaces ) ? [] : $workspaces;
		?>
		<div class="wrap gx-zb-services">
			<h1>
				<?php esc_html_e( 'Zoho Bookings Services', 'gx-zoho-bookings' ); ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'new', 'workspace' => $workspace_id ), admin_url( 'admin.php?page=gx-zb-services' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Service', 'gx-zoho-bookings' ); ?></a>
			</h1>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="gx-zb-services">
				<label for="gx-zb-workspace-select"><?php esc_html_e( 'Select Workspace:', 'gx-zoho-bookings' ); ?></label>
				<select name="workspace" id="gx-zb-workspace-select" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'All Workspaces', 'gx-zoho-bookings' ); ?></option>
					<?php foreach ( $workspace_list as $ws ) : ?>
						<option value="<?php echo esc_attr( $ws['id'] ?? $ws['workspace_id'] ?? '' ); ?>" <?php selected( $workspace_id, $ws['id'] ?? $ws['workspace_id'] ?? '' ); ?>><?php echo esc_html( $ws['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>

			<?php
			$services = $api_client->get_services( $workspace_id );
			if ( is_wp_error( $services ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $services->get_error_message() ) . '</p></div>';
			} elseif ( empty( $services ) ) {
				echo '<p>' . esc_html__( 'No services found.', 'gx-zoho-bookings' ) . '</p>';
			} else {
				$this->render_services_table( $services, $workspace_id );
			}

			$this->render_create_workspace_form();
			?>
		</div>
		<?php
	}

	private function render_services_table( $services, $workspace_id ) {
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'gx-zoho-bookings' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'gx-zoho-bookings' ); ?></th>
					<th><?php esc_html_e( 'Cost', 'gx-zoho-bookings' ); ?></th>
					<th><?php esc_html_e( 'Mode', 'gx-zoho-bookings' ); ?></th>
					<th><?php esc_html_e( 'Status', 'gx-zoho-bookings' ); ?></th>
					<th><?php esc_html_e( 'Staff', 'gx-zoho-bookings' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'gx-zoho-bookings' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $services as $service ) :
				$id       = $service['id'] ?? $service['service_id'] ?? '';
				$name     = $service['name'] ?? $service['service_name'] ?? '';
				$duration = $service['duration'] ?? 0;
				$cost     = $service['cost'] ?? 0;
				$mode     = $service['meeting_mode'] ?? 'offline';
				$status   = $service['status'] ?? $service['booking_status'] ?? 'active';
				$staff_count = 0;
				if ( ! empty( $service['assigned_staffs'] ) && is_array( $service['assigned_staffs'] ) ) {
					$staff_count = count( $service['assigned_staffs'] );
				} elseif ( ! empty( $service['staff_count'] ) ) {
					$staff_count = (int) $service['staff_count'];
				}
				?>
				<tr>
					<td><?php echo esc_html( $name ); ?></td>
					<td><?php echo esc_html( $duration ); ?> min</td>
					<td><?php echo esc_html( $cost ? '$' . number_format_i18n( (float) $cost, 2 ) : '—' ); ?></td>
					<td><?php echo esc_html( ucfirst( $mode ) ); ?></td>
					<td><span class="gx-zb-badge gx-zb-badge-<?php echo ( 'active' === $status ) ? 'completed' : 'cancel'; ?>"><?php echo esc_html( $status ); ?></span></td>
					<td><?php echo (int) $staff_count; ?></td>
					<td>
						<?php
						$edit_url      = add_query_arg( [ 'page' => 'gx-zb-services', 'view' => 'edit', 'id' => $id, 'workspace' => $workspace_id ], admin_url( 'admin.php' ) );
						$toggle_action = ( 'active' === $status ) ? 'deactivate' : 'activate';
						$post_url      = admin_url( 'admin-post.php' );
						// State-changing actions post via form so the nonce travels in
						// the request body, not the URL (no Referer leakage).
						?>
						<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'gx-zoho-bookings' ); ?></a>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline">
							<?php wp_nonce_field( 'gx_zb_service_status_' . $id ); ?>
							<input type="hidden" name="action" value="gx_zb_service_status">
							<input type="hidden" name="service_id" value="<?php echo esc_attr( $id ); ?>">
							<input type="hidden" name="gx_action" value="<?php echo esc_attr( $toggle_action ); ?>">
							<input type="hidden" name="workspace" value="<?php echo esc_attr( $workspace_id ); ?>">
							<button type="submit" class="button-link"><?php echo ( 'active' === $status ) ? esc_html__( 'Deactivate', 'gx-zoho-bookings' ) : esc_html__( 'Activate', 'gx-zoho-bookings' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline" class="gx-zb-confirm-form" data-confirm="<?php esc_attr_e( 'Are you sure you want to delete this service? This cannot be undone.', 'gx-zoho-bookings' ); ?>">
							<?php wp_nonce_field( 'gx_zb_service_delete_' . $id ); ?>
							<input type="hidden" name="action" value="gx_zb_service_delete">
							<input type="hidden" name="service_id" value="<?php echo esc_attr( $id ); ?>">
							<input type="hidden" name="workspace" value="<?php echo esc_attr( $workspace_id ); ?>">
							<button type="submit" class="button-link" style="color:#b32d2e"><?php esc_html_e( 'Delete', 'gx-zoho-bookings' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_create_workspace_form() {
		$nonce = wp_create_nonce( 'gx_zb_workspace_create' );
		?>
		<div class="gx-zb-card" style="margin-top:20px; padding:15px;">
			<h2><?php esc_html_e( 'Create New Workspace', 'gx-zoho-bookings' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gx_zb_workspace_create">
				<?php wp_nonce_field( 'gx_zb_workspace_create', '_wpnonce', false ); ?>
				<label for="gx-zb-workspace-name"><?php esc_html_e( 'Workspace Name:', 'gx-zoho-bookings' ); ?></label>
				<input type="text" id="gx-zb-workspace-name" name="name" required maxlength="50" pattern="[^|/\\,?{}<>:;&quot;'`]+" title="<?php esc_attr_e( 'Name must be 2-50 characters and cannot contain |/\\,?{}<>:;&#34;&#39;`', 'gx-zoho-bookings' ); ?>" />
				<p class="description"><?php esc_html_e( 'Only one workspace is allowed on the free plan.', 'gx-zoho-bookings' ); ?></p>
				<?php submit_button( esc_html__( 'Create Workspace', 'gx-zoho-bookings' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private function render_new_form( $workspace_id ) {
		$api_client = GX_ZB_API_Client::instance();
		$staff      = $api_client->get_staff();
		$staff_list = is_wp_error( $staff ) ? [] : $staff;
		$workspaces = $api_client->get_workspaces();
		$workspace_list = is_wp_error( $workspaces ) ? array() : $workspaces;
		?>
		<div class="wrap gx-zb-services">
			<h1><?php esc_html_e( 'Add New Service', 'gx-zoho-bookings' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gx-zb-booking-form">
				<input type="hidden" name="action" value="gx_zb_service_save">
				<?php wp_nonce_field( 'gx_zb_service_save' ); ?>
				<?php if ( $workspace_id ) : ?>
					<input type="hidden" name="workspace_id" value="<?php echo esc_attr( $workspace_id ); ?>">
				<?php endif; ?>

				<table class="form-table">
					<?php if ( ! $workspace_id ) : ?>
					<tr>
						<th><label for="gx-zb-service-workspace"><?php esc_html_e( 'Workspace', 'gx-zoho-bookings' ); ?></label></th>
						<td>
							<select id="gx-zb-service-workspace" name="workspace_id" required>
								<option value=""><?php esc_html_e( 'Select a workspace…', 'gx-zoho-bookings' ); ?></option>
								<?php foreach ( $workspace_list as $ws ) :
									$ws_id   = $ws['id'] ?? $ws['workspace_id'] ?? '';
									$ws_name = $ws['name'] ?? '';
									?>
									<option value="<?php echo esc_attr( $ws_id ); ?>"><?php echo esc_html( $ws_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th><label for="gx-zb-service-name"><?php esc_html_e( 'Name', 'gx-zoho-bookings' ); ?></label></th>
						<td><input type="text" id="gx-zb-service-name" name="name" required class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-duration"><?php esc_html_e( 'Duration (minutes)', 'gx-zoho-bookings' ); ?></label></th>
						<td><input type="number" id="gx-zb-service-duration" name="duration" min="5" step="5" value="30" class="small-text"></td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-cost"><?php esc_html_e( 'Cost', 'gx-zoho-bookings' ); ?></label></th>
						<td><input type="number" id="gx-zb-service-cost" name="cost" min="0" step="0.01" class="small-text" placeholder="0.00"></td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-description"><?php esc_html_e( 'Description', 'gx-zoho-bookings' ); ?></label></th>
						<td><textarea id="gx-zb-service-description" name="description" rows="4" class="large-text"></textarea></td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-pre-buffer"><?php esc_html_e( 'Pre-buffer (min)', 'gx-zoho-bookings' ); ?></label></th>
						<td><input type="number" id="gx-zb-service-pre-buffer" name="pre_buffer" min="0" class="small-text" value="0"></td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-post-buffer"><?php esc_html_e( 'Post-buffer (min)', 'gx-zoho-bookings' ); ?></label></th>
						<td><input type="number" id="gx-zb-service-post-buffer" name="post_buffer" min="0" class="small-text" value="0"></td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-mode"><?php esc_html_e( 'Meeting Mode', 'gx-zoho-bookings' ); ?></label></th>
						<td>
							<select id="gx-zb-service-mode" name="meeting_mode">
								<option value="offline"><?php esc_html_e( 'Offline', 'gx-zoho-bookings' ); ?></option>
								<option value="online"><?php esc_html_e( 'Online', 'gx-zoho-bookings' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'When online, select the meeting type below.', 'gx-zoho-bookings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-meeting-type"><?php esc_html_e( 'Meeting Type', 'gx-zoho-bookings' ); ?></label></th>
						<td>
							<select id="gx-zb-service-meeting-type" name="meeting_type">
								<option value=""><?php esc_html_e( 'None', 'gx-zoho-bookings' ); ?></option>
								<option value="zohomeeting"><?php esc_html_e( 'Zoho Meeting', 'gx-zoho-bookings' ); ?></option>
								<option value="zoom"><?php esc_html_e( 'Zoom', 'gx-zoho-bookings' ); ?></option>
								<option value="teams"><?php esc_html_e( 'Microsoft Teams', 'gx-zoho-bookings' ); ?></option>
								<option value="gmeet"><?php esc_html_e( 'Google Meet', 'gx-zoho-bookings' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Assign Staff', 'gx-zoho-bookings' ); ?></label></th>
						<td>
							<?php if ( ! empty( $staff_list ) ) : ?>
								<?php foreach ( $staff_list as $s ) :
									$sid   = $s['id'] ?? $s['staff_id'] ?? '';
									$sname = $s['name'] ?? $s['staff_name'] ?? '';
									?>
									<label style="display:block; margin-bottom:4px;">
										<input type="checkbox" name="assigned_staffs[]" value="<?php echo esc_attr( $sid ); ?>"> <?php echo esc_html( $sname ); ?>
									</label>
								<?php endforeach; ?>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'No staff members found. You can add staff in Zoho Bookings.', 'gx-zoho-bookings' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( esc_html__( 'Create Service', 'gx-zoho-bookings' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the edit-service form, pre-filled from the current service row.
	 *
	 * @param string $workspace_id Current workspace filter.
	 * @param array  $service      Service row from get_services().
	 * @return void
	 */
	private function render_edit_form( $workspace_id, $service ) {
		$api_client = GX_ZB_API_Client::instance();
		$staff      = $api_client->get_staff();
		$staff_list = is_wp_error( $staff ) ? array() : $staff;
		$id         = isset( $service['id'] ) ? $service['id'] : ( isset( $service['service_id'] ) ? $service['service_id'] : '' );

		$cur_mode = isset( $service['meeting_mode'] ) ? $service['meeting_mode'] : 'offline';
		$cur_type = isset( $service['meeting_type'] ) ? $service['meeting_type'] : '';
		$cur_stat = isset( $service['status'] ) ? $service['status'] : ( isset( $service['booking_status'] ) ? $service['booking_status'] : 'active' );

		// Assigned staff ids, tolerating both id-list and object-list shapes.
		$assigned = array();
		if ( ! empty( $service['assigned_staffs'] ) && is_array( $service['assigned_staffs'] ) ) {
			foreach ( $service['assigned_staffs'] as $as ) {
				if ( is_array( $as ) ) {
					$assigned[] = isset( $as['id'] ) ? $as['id'] : ( isset( $as['staff_id'] ) ? $as['staff_id'] : '' );
				} else {
					$assigned[] = $as;
				}
			}
		}

		$types = array(
			''            => __( 'None', 'gx-zoho-bookings' ),
			'zohomeeting' => __( 'Zoho Meeting', 'gx-zoho-bookings' ),
			'zoom'        => __( 'Zoom', 'gx-zoho-bookings' ),
			'teams'       => __( 'Microsoft Teams', 'gx-zoho-bookings' ),
			'gmeet'       => __( 'Google Meet', 'gx-zoho-bookings' ),
		);
		?>
		<div class="wrap gx-zb-services">
			<h1><?php esc_html_e( 'Edit Service', 'gx-zoho-bookings' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gx-zb-booking-form">
				<input type="hidden" name="action" value="gx_zb_service_save">
				<?php wp_nonce_field( 'gx_zb_service_save' ); ?>
				<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
				<input type="hidden" name="workspace_id" value="<?php echo esc_attr( isset( $service['workspace_id'] ) ? $service['workspace_id'] : $workspace_id ); ?>">

				<table class="form-table">
					<tr>
						<th><label for="gx-zb-service-name"><?php esc_html_e( 'Name', 'gx-zoho-bookings' ); ?></label></th>
						<td><input type="text" id="gx-zb-service-name" name="name" value="<?php echo esc_attr( isset( $service['name'] ) ? $service['name'] : ( isset( $service['service_name'] ) ? $service['service_name'] : '' ) ); ?>" required class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-duration"><?php esc_html_e( 'Duration (minutes)', 'gx-zoho-bookings' ); ?></label></th>
						<td><input type="number" id="gx-zb-service-duration" name="duration" min="5" step="5" value="<?php echo esc_attr( isset( $service['duration'] ) ? $service['duration'] : 30 ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-cost"><?php esc_html_e( 'Cost', 'gx-zoho-bookings' ); ?></label></th>
						<td><input type="number" id="gx-zb-service-cost" name="cost" min="0" step="0.01" value="<?php echo esc_attr( isset( $service['cost'] ) ? $service['cost'] : '' ); ?>" class="small-text" placeholder="0.00"></td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-description"><?php esc_html_e( 'Description', 'gx-zoho-bookings' ); ?></label></th>
						<td><textarea id="gx-zb-service-description" name="description" rows="4" class="large-text"><?php echo esc_textarea( isset( $service['description'] ) ? $service['description'] : '' ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-pre-buffer"><?php esc_html_e( 'Pre-buffer (min)', 'gx-zoho-bookings' ); ?></label></th>
						<td><input type="number" id="gx-zb-service-pre-buffer" name="pre_buffer" min="0" value="<?php echo esc_attr( isset( $service['pre_buffer'] ) ? $service['pre_buffer'] : 0 ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-post-buffer"><?php esc_html_e( 'Post-buffer (min)', 'gx-zoho-bookings' ); ?></label></th>
						<td><input type="number" id="gx-zb-service-post-buffer" name="post_buffer" min="0" value="<?php echo esc_attr( isset( $service['post_buffer'] ) ? $service['post_buffer'] : 0 ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-status"><?php esc_html_e( 'Status', 'gx-zoho-bookings' ); ?></label></th>
						<td>
							<select id="gx-zb-service-status" name="status">
								<option value="active" <?php selected( $cur_stat, 'active' ); ?>><?php esc_html_e( 'Active', 'gx-zoho-bookings' ); ?></option>
								<option value="in_active" <?php selected( $cur_stat, 'in_active' ); ?>><?php esc_html_e( 'Inactive', 'gx-zoho-bookings' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-mode"><?php esc_html_e( 'Meeting Mode', 'gx-zoho-bookings' ); ?></label></th>
						<td>
							<select id="gx-zb-service-mode" name="meeting_mode">
								<option value="offline" <?php selected( $cur_mode, 'offline' ); ?>><?php esc_html_e( 'Offline', 'gx-zoho-bookings' ); ?></option>
								<option value="online" <?php selected( $cur_mode, 'online' ); ?>><?php esc_html_e( 'Online', 'gx-zoho-bookings' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'When online, a meeting type is required.', 'gx-zoho-bookings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="gx-zb-service-meeting-type"><?php esc_html_e( 'Meeting Type', 'gx-zoho-bookings' ); ?></label></th>
						<td>
							<select id="gx-zb-service-meeting-type" name="meeting_type">
								<?php foreach ( $types as $tval => $tlabel ) : ?>
									<option value="<?php echo esc_attr( $tval ); ?>" <?php selected( $cur_type, $tval ); ?>><?php echo esc_html( $tlabel ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Assign Staff', 'gx-zoho-bookings' ); ?></label></th>
						<td>
							<?php if ( ! empty( $staff_list ) ) : ?>
								<?php
								foreach ( $staff_list as $s ) :
									$sid   = isset( $s['id'] ) ? $s['id'] : ( isset( $s['staff_id'] ) ? $s['staff_id'] : '' );
									$sname = isset( $s['name'] ) ? $s['name'] : ( isset( $s['staff_name'] ) ? $s['staff_name'] : '' );
									?>
									<label style="display:block; margin-bottom:4px;">
										<input type="checkbox" name="assigned_staffs[]" value="<?php echo esc_attr( $sid ); ?>" <?php checked( in_array( (string) $sid, array_map( 'strval', $assigned ), true ) ); ?>> <?php echo esc_html( $sname ); ?>
									</label>
								<?php endforeach; ?>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'No staff members found.', 'gx-zoho-bookings' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php // FUTURE (paid plan): resource-service and group-booking fields. ?>
				<?php submit_button( esc_html__( 'Save Changes', 'gx-zoho-bookings' ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=gx-zb-services' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'gx-zoho-bookings' ); ?></a>
			</form>
		</div>
		<?php
	}

	/**
	 * Setup prompt shown when API mode is off or Zoho is not connected.
	 *
	 * @return void
	 */
	private function render_setup_prompt() {
		?>
		<div class="wrap gx-zb-services">
			<h1><?php esc_html_e( 'Zoho Bookings Services', 'gx-zoho-bookings' ); ?></h1>
			<div class="gx-zb-status-card is-disconnected" style="padding:20px;border:1px solid #ccd0d4;border-radius:8px;background:#fff;">
				<p><?php esc_html_e( 'Managing services requires API mode with an active Zoho connection.', 'gx-zoho-bookings' ); ?></p>
				<p><a href="<?php echo esc_url( admin_url( 'options-general.php?page=gx-zoho-bookings' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Go to Settings & Connection', 'gx-zoho-bookings' ); ?></a></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render an inline error notice for a WP_Error.
	 *
	 * @param WP_Error $error Error to display.
	 * @return void
	 */
	private function render_error_notice( $error ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $error->get_error_message() ) . '</p></div>';
	}

	/**
	 * Redirect helper back to the services list with a message flag.
	 *
	 * @param string $msg    gx_zb_msg value.
	 * @param string $detail Optional detail appended for failures.
	 * @return void
	 */
	private function redirect_list( $msg, $detail = '' ) {
		$args = array(
			'page'      => 'gx-zb-services',
			'gx_zb_msg' => $msg,
		);
		if ( '' !== $detail ) {
			$args['gx_zb_detail'] = rawurlencode( $detail );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle create/update service submission.
	 *
	 * @return void
	 */
	public function handle_save_service() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'gx-zoho-bookings' ) );
		}
		check_admin_referer( 'gx_zb_service_save' );

		$id           = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$workspace_id = isset( $_POST['workspace_id'] ) ? sanitize_text_field( wp_unslash( $_POST['workspace_id'] ) ) : '';
		$duration     = isset( $_POST['duration'] ) ? absint( $_POST['duration'] ) : 0;
		$cost         = isset( $_POST['cost'] ) && '' !== $_POST['cost'] ? max( 0, (float) wp_unslash( $_POST['cost'] ) ) : null;
		$description  = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$pre_buffer   = isset( $_POST['pre_buffer'] ) ? absint( $_POST['pre_buffer'] ) : 0;
		$post_buffer  = isset( $_POST['post_buffer'] ) ? absint( $_POST['post_buffer'] ) : 0;

		$mode = isset( $_POST['meeting_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['meeting_mode'] ) ) : 'offline';
		if ( ! in_array( $mode, array( 'online', 'offline' ), true ) ) {
			$mode = 'offline';
		}
		$type = isset( $_POST['meeting_type'] ) ? sanitize_text_field( wp_unslash( $_POST['meeting_type'] ) ) : '';
		if ( ! in_array( $type, array( '', 'zohomeeting', 'zoom', 'teams', 'gmeet' ), true ) ) {
			$type = '';
		}
		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! in_array( $status, array( 'active', 'in_active' ), true ) ) {
			$status = '';
		}

		$assigned = array();
		if ( isset( $_POST['assigned_staffs'] ) && is_array( $_POST['assigned_staffs'] ) ) {
			$assigned = array_map( 'sanitize_text_field', wp_unslash( $_POST['assigned_staffs'] ) );
		}

		if ( '' === $name ) {
			$this->redirect_list( 'service_save_failed', __( 'Service name is required.', 'gx-zoho-bookings' ) );
		}
		if ( 'online' === $mode && '' === $type ) {
			$this->redirect_list( 'service_save_failed', __( 'A meeting type is required when the mode is online.', 'gx-zoho-bookings' ) );
		}

		$args = array(
			'name'            => $name,
			'duration'        => $duration ? $duration : 30,
			'description'     => $description,
			'pre_buffer'      => $pre_buffer,
			'post_buffer'     => $post_buffer,
			'meeting_mode'    => $mode,
			'meeting_type'    => $type,
			'assigned_staffs' => $assigned,
		);
		if ( null !== $cost ) {
			$args['cost'] = $cost;
		}

		$client = GX_ZB_API_Client::instance();

		if ( '' !== $id ) {
			$args['id'] = $id;
			if ( '' !== $status ) {
				$args['status'] = $status;
			}
			$result = $client->update_service( $args );
		} else {
			if ( '' === $workspace_id ) {
				$this->redirect_list( 'service_save_failed', __( 'Select a workspace before creating a service.', 'gx-zoho-bookings' ) );
			}
			$args['workspace_id'] = $workspace_id;
			$result               = $client->create_service( $args );
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect_list( 'service_save_failed', $result->get_error_message() );
		}
		$this->redirect_list( 'service_saved' );
	}

	/**
	 * Handle service deletion.
	 *
	 * @return void
	 */
	public function handle_delete_service() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'gx-zoho-bookings' ) );
		}
		$id = isset( $_POST['service_id'] ) ? sanitize_text_field( wp_unslash( $_POST['service_id'] ) ) : '';
		if ( '' === $id ) {
			wp_die( esc_html__( 'Missing service id.', 'gx-zoho-bookings' ) );
		}
		check_admin_referer( 'gx_zb_service_delete_' . $id );

		$result = GX_ZB_API_Client::instance()->delete_service( $id );
		if ( is_wp_error( $result ) ) {
			$this->redirect_list( 'service_delete_failed', $result->get_error_message() );
		}
		$this->redirect_list( 'service_deleted' );
	}

	/**
	 * Handle activate/deactivate toggle (thin wrapper over update_service status).
	 *
	 * @return void
	 */
	public function handle_toggle_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'gx-zoho-bookings' ) );
		}
		$id     = isset( $_POST['service_id'] ) ? sanitize_text_field( wp_unslash( $_POST['service_id'] ) ) : '';
		$action = isset( $_POST['gx_action'] ) ? sanitize_text_field( wp_unslash( $_POST['gx_action'] ) ) : '';
		if ( '' === $id ) {
			wp_die( esc_html__( 'Missing service id.', 'gx-zoho-bookings' ) );
		}
		check_admin_referer( 'gx_zb_service_status_' . $id );

		$status = ( 'activate' === $action ) ? 'active' : 'in_active';
		$result = GX_ZB_API_Client::instance()->update_service(
			array(
				'id'     => $id,
				'status' => $status,
			)
		);
		if ( is_wp_error( $result ) ) {
			$this->redirect_list( 'service_save_failed', $result->get_error_message() );
		}
		$this->redirect_list( 'service_saved' );
	}

	/**
	 * Handle workspace creation.
	 *
	 * @return void
	 */
	public function handle_create_workspace() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'gx-zoho-bookings' ) );
		}
		check_admin_referer( 'gx_zb_workspace_create' );

		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$result = GX_ZB_API_Client::instance()->create_workspace( $name );
		if ( is_wp_error( $result ) ) {
			$this->redirect_list( 'workspace_create_failed', $result->get_error_message() );
		}
		$this->redirect_list( 'workspace_created' );
	}
}
