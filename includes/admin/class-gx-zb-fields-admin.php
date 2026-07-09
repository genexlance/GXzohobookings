<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin page for per-service custom booking fields.
 *
 * @since 2.0.0
 */
final class GX_ZB_Fields_Admin {

	/**
	 * Singleton instance.
	 *
	 * @since 2.0.0
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Private constructor.
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
	 * Register WordPress hooks.
	 *
	 * @since 2.0.0
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_gx_zb_fields_save', array( $this, 'handle_save' ) );
	}

	/**
	 * Add the submenu page.
	 *
	 * @since 2.0.0
	 */
	public function add_menu() {
		add_submenu_page(
			'gx-zb-dashboard',
			__( 'Custom Fields', 'gx-zoho-bookings' ),
			__( 'Custom Fields', 'gx-zoho-bookings' ),
			'manage_options',
			'gx-zb-fields',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the custom fields admin page.
	 *
	 * @since 2.0.0
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gx-zoho-bookings' ) );
		}

		if ( ! GX_ZB_OAuth::instance()->is_connected() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Connect to Zoho first.', 'gx-zoho-bookings' ) . '</p></div>';
			return;
		}

		$services = GX_ZB_API_Client::instance()->get_services();
		if ( is_wp_error( $services ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $services->get_error_message() ) . '</p></div>';
			return;
		}

		if ( empty( $services ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No services found.', 'gx-zoho-bookings' ) . '</p></div>';
			return;
		}

		$selected_service = isset( $_GET['service'] ) ? sanitize_text_field( wp_unslash( $_GET['service'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Custom Fields', 'gx-zoho-bookings' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Fields saved successfully.', 'gx-zoho-bookings' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['error'] ) && '1' === $_GET['error'] ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Invalid service.', 'gx-zoho-bookings' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="gx-zb-fields" />
				<p>
					<label for="service-selector"><?php esc_html_e( 'Choose a service:', 'gx-zoho-bookings' ); ?></label>
					<select name="service" id="service-selector" onchange="this.form.submit()">
						<?php foreach ( $services as $service_data ) : ?>
							<?php
							$service_id   = isset( $service_data['id'] ) ? $service_data['id'] : ( isset( $service_data['service_id'] ) ? $service_data['service_id'] : '' );
							$service_name = isset( $service_data['name'] ) ? $service_data['name'] : '';
							?>
							<option value="<?php echo esc_attr( $service_id ); ?>" <?php selected( $selected_service, $service_id ); ?>>
								<?php echo esc_html( $service_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
			</form>

			<?php if ( ! empty( $selected_service ) ) : ?>
				<?php
				$existing_defs = GX_ZB_Fields::instance()->get( $selected_service );
				if ( ! is_array( $existing_defs ) ) {
					$existing_defs = array();
				}
				$row_count = min( max( count( $existing_defs ), 3 ), 10 );
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'gx_zb_fields_save' ); ?>
					<input type="hidden" name="action" value="gx_zb_fields_save" />
					<input type="hidden" name="service" value="<?php echo esc_attr( $selected_service ); ?>" />

					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Label', 'gx-zoho-bookings' ); ?></th>
								<th><?php esc_html_e( 'Key', 'gx-zoho-bookings' ); ?></th>
								<th><?php esc_html_e( 'Type', 'gx-zoho-bookings' ); ?></th>
								<th><?php esc_html_e( 'Options', 'gx-zoho-bookings' ); ?></th>
								<th><?php esc_html_e( 'Required', 'gx-zoho-bookings' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php for ( $i = 0; $i < $row_count; $i++ ) : ?>
								<?php
								$def = isset( $existing_defs[ $i ] ) ? $existing_defs[ $i ] : array(
									'label'    => '',
									'key'      => '',
									'type'     => 'text',
									'options'  => '',
									'required' => false,
								);
								?>
								<tr>
									<td>
										<input type="text" name="defs[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $def['label'] ); ?>" />
									</td>
									<td>
										<input type="text" name="defs[<?php echo (int) $i; ?>][key]" value="<?php echo esc_attr( $def['key'] ); ?>" placeholder="<?php esc_attr_e( 'auto', 'gx-zoho-bookings' ); ?>" />
									</td>
									<td>
										<select name="defs[<?php echo (int) $i; ?>][type]">
											<?php
											$types = array( 'text', 'textarea', 'select', 'checkbox' );
											foreach ( $types as $type ) {
												printf(
													'<option value="%s" %s>%s</option>',
													esc_attr( $type ),
													selected( $def['type'], $type, false ),
													esc_html( ucfirst( $type ) )
												);
											}
											?>
										</select>
									</td>
									<td>
										<input type="text" name="defs[<?php echo (int) $i; ?>][options]" value="<?php echo esc_attr( is_array( $def['options'] ) ? implode( ',', $def['options'] ) : $def['options'] ); ?>" />
									</td>
									<td>
										<input type="checkbox" name="defs[<?php echo (int) $i; ?>][required]" value="1" <?php checked( $def['required'], true ); ?> />
									</td>
								</tr>
							<?php endfor; ?>
						</tbody>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save fields', 'gx-zoho-bookings' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the save action for custom fields.
	 *
	 * @since 2.0.0
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'gx-zoho-bookings' ) );
		}

		check_admin_referer( 'gx_zb_fields_save' );

		$service = isset( $_POST['service'] ) ? sanitize_text_field( wp_unslash( $_POST['service'] ) ) : '';
		if ( empty( $service ) ) {
			wp_safe_redirect( add_query_arg( array(
				'page'  => 'gx-zb-fields',
				'error' => '1',
			), admin_url( 'admin.php' ) ) );
			exit;
		}

		$raw_defs = isset( $_POST['defs'] ) && is_array( $_POST['defs'] ) ? wp_unslash( $_POST['defs'] ) : array();
		$defs     = array();

		foreach ( $raw_defs as $index => $row ) {
			$row = (array) $row;
			$label = isset( $row['label'] ) ? trim( $row['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}

			$defs[] = array(
				'label'    => $label,
				'key'      => isset( $row['key'] ) ? $row['key'] : '',
				'type'     => isset( $row['type'] ) ? $row['type'] : 'text',
				'options'  => isset( $row['options'] ) ? $row['options'] : '',
				'required' => isset( $row['required'] ) ? true : false,
			);
		}

		GX_ZB_Fields::instance()->save( $service, $defs );

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'gx-zb-fields',
			'service' => $service,
			'updated' => '1',
		), admin_url( 'admin.php' ) ) );
		exit;
	}
}
