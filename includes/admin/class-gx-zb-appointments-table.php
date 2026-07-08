<?php
defined( 'ABSPATH' ) || exit;

/**
 * GX_ZB_Appointments_Table — WP_List_Table subclass for displaying Zoho Bookings appointments in the WordPress admin.
 *
 * @package GX_Zoho_Bookings
 * @since   1.1.0
 */

// Load WP_List_Table if not already loaded.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Appointments list table class.
 */
class GX_ZB_Appointments_Table extends WP_List_Table {

	/**
	 * Raw appointment data fetched from the API.
	 *
	 * @since 1.1.0
	 * @var   array
	 */
	private $appointments = array();

	/**
	 * Whether the API returned an error.
	 *
	 * @since 1.1.0
	 * @var   WP_Error|false
	 */
	private $error = false;

	/**
	 * Current page number.
	 *
	 * @since 1.1.0
	 * @var   int
	 */
	private $current_page = 1;

	/**
	 * Results per page.
	 *
	 * @since 1.1.0
	 * @var   int
	 */
	private $per_page = 20;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args Associative array of arguments.
	 */
	public function __construct( $args = array() ) {
		parent::__construct(
			array(
				'singular' => 'appointment',
				'plural'   => 'appointments',
				'ajax'     => false,
				'screen'   => isset( $args['screen'] ) ? $args['screen'] : null,
			)
		);

		$this->per_page = apply_filters( 'gx_zb_appointments_per_page', 20 );
	}

	/**
	 * Define columns.
	 *
	 * @since 1.1.0
	 * @return array
	 */
	public function get_columns() {
		$columns = array(
			'booking_id'  => __( 'Booking ID', 'gx-zoho-bookings' ),
			'customer'    => __( 'Customer', 'gx-zoho-bookings' ),
			'service_name'=> __( 'Service', 'gx-zoho-bookings' ),
			'staff_name'  => __( 'Staff', 'gx-zoho-bookings' ),
			'start_time'  => __( 'Date / Time', 'gx-zoho-bookings' ),
			'duration'    => __( 'Duration', 'gx-zoho-bookings' ),
			'status'      => __( 'Status', 'gx-zoho-bookings' ),
			'actions'     => __( 'Actions', 'gx-zoho-bookings' ),
		);

		return $columns;
	}

	/**
	 * Define sortable columns.
	 *
	 * @since 1.1.0
	 * @return array
	 */
	protected function get_sortable_columns() {
		$sortable = array(
			'start_time'  => array( 'start_time', false ),
			'booking_id'  => array( 'booking_id', false ),
			'status'      => array( 'status', false ),
		);

		return $sortable;
	}

	/**
	 * Prepare items for display — fetch from API, sort, slice for pagination.
	 *
	 * @since 1.1.0
	 */
	public function prepare_items() {
		$this->current_page = $this->get_pagenum();

		// Sanitize filter parameters from $_GET.
		$filters = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended — filtering uses GET params, no state change.
		if ( ! empty( $_GET['gx_zb_status'] ) ) {
			$status = sanitize_text_field( wp_unslash( $_GET['gx_zb_status'] ) );
			if ( ! in_array( $status, array( 'upcoming', 'completed', 'cancel', 'noshow' ), true ) ) {
				$status = '';
			}
			if ( '' !== $status ) {
				$filters['status'] = $status;
			}
		}

		$from_time = '';
		if ( ! empty( $_GET['gx_zb_from'] ) ) {
			$from_time = sanitize_text_field( wp_unslash( $_GET['gx_zb_from'] ) );
		}

		$to_time = '';
		if ( ! empty( $_GET['gx_zb_to'] ) ) {
			$to_time = sanitize_text_field( wp_unslash( $_GET['gx_zb_to'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Convert Y-m-d to dd-MMM-yyyy Zoho format.
		if ( '' !== $from_time ) {
			$dt = DateTime::createFromFormat( 'Y-m-d', $from_time );
			if ( $dt ) {
				$filters['from_time'] = $dt->format( 'd-M-Y' );
			}
		}

		if ( '' !== $to_time ) {
			$dt = DateTime::createFromFormat( 'Y-m-d', $to_time );
			if ( $dt ) {
				$filters['to_time'] = $dt->format( 'd-M-Y' );
			}
		}

		// Fetch from API.
		$api_client = GX_ZB_API_Client::instance();

		if ( ! $api_client || ! class_exists( 'GX_ZB_API_Client' ) ) {
			$this->error = new WP_Error( 'gx_zb_not_connected', __( 'API client not available.', 'gx-zoho-bookings' ) );
			$this->appointments = array();
		} else {
			$result = $api_client->get_appointments( $filters );

			if ( is_wp_error( $result ) ) {
				$this->error = $result;
				$this->appointments = array();
			} elseif ( ! is_array( $result ) ) {
				$this->appointments = array();
			} else {
				$this->appointments = $result;
			}
		}

		// Sort the data. Whitelist orderby against the sortable columns.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'start_time'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! array_key_exists( $orderby, $this->get_sortable_columns() ) ) {
			$orderby = 'start_time';
		}
		$order   = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$order = 'desc';
		}

		if ( ! empty( $this->appointments ) ) {
			usort(
				$this->appointments,
				function ( $a, $b ) use ( $orderby, $order ) {
					$val_a = isset( $a[ $orderby ] ) ? $a[ $orderby ] : '';
					$val_b = isset( $b[ $orderby ] ) ? $b[ $orderby ] : '';

					if ( 'start_time' === $orderby ) {
						$ts_a = strtotime( $val_a );
						$ts_b = strtotime( $val_b );
						if ( 'asc' === $order ) {
							return $ts_a - $ts_b;
						}
						return $ts_b - $ts_a;
					}

					$cmp = strcasecmp( (string) $val_a, (string) $val_b );
					return 'asc' === $order ? $cmp : -$cmp;
				}
			);
		}

		// Total items.
		$total_items = count( $this->appointments );

		// Slice for current page.
		$offset = ( $this->current_page - 1 ) * $this->per_page;
		$this->items = array_slice( $this->appointments, $offset, $this->per_page );

		// Pagination.
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $this->per_page,
				'total_pages' => ceil( $total_items / $this->per_page ),
			)
		);

		// Columns.
		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );
	}

	/**
	 * Message displayed when no items are found.
	 *
	 * @since 1.1.0
	 */
	public function no_items() {
		if ( is_wp_error( $this->error ) ) {
			/* translators: %s: error message */
			printf(
				'<p>' . esc_html__( 'Could not load appointments: %s', 'gx-zoho-bookings' ) . '</p>',
				esc_html( $this->error->get_error_message() )
			);
		} else {
			esc_html_e( 'No appointments found.', 'gx-zoho-bookings' );
		}
	}

	/**
	 * Render a column value with no specific method.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $item        The current item.
	 * @param string $column_name The column name.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		// phpcs:enable Squiz.PHP.CommentedOutCode.Found
		switch ( $column_name ) {
			case 'booking_id':
				$id = $this->get_item_value( $item, 'booking_id' );
				return '<code>' . esc_html( $id ) . '</code>';

			case 'service_name':
				return esc_html( $this->get_item_value( $item, 'service_name' ) );

			case 'staff_name':
				return esc_html( $this->get_item_value( $item, 'staff_name' ) );

			case 'duration':
				$duration = $this->get_item_value( $item, 'duration' );
				if ( is_numeric( $duration ) ) {
					$duration = sprintf(
						/* translators: %d: number of minutes */
						_n( '%d min', '%d mins', (int) $duration, 'gx-zoho-bookings' ),
						(int) $duration
					);
				}
				return esc_html( $duration );

			default:
				return esc_html( $this->get_item_value( $item, $column_name ) );
		}
	}

	/**
	 * Render the customer column (name + email small).
	 *
	 * @since 1.1.0
	 *
	 * @param array $item The current item.
	 * @return string
	 */
	protected function column_customer( $item ) {
		$name  = $this->get_item_value( $item, 'customer_name' );
		$email = $this->get_item_value( $item, 'customer_email' );

		$output = '<strong>' . esc_html( $name ) . '</strong>';
		if ( ! empty( $email ) ) {
			$output .= '<br><small>' . esc_html( $email ) . '</small>';
		}

		return $output;
	}

	/**
	 * Render the start_time column (formatted date/time).
	 *
	 * @since 1.1.0
	 *
	 * @param array $item The current item.
	 * @return string
	 */
	protected function column_start_time( $item ) {
		$raw = $this->get_item_value( $item, 'start_time' );
		if ( empty( $raw ) ) {
			return '—';
		}

		$ts = strtotime( $raw );
		if ( false === $ts ) {
			return esc_html( $raw );
		}

		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );
		$date        = wp_date( $date_format, $ts );
		$time        = wp_date( $time_format, $ts );

		return '<strong>' . esc_html( $date ) . '</strong><br><small>' . esc_html( $time ) . '</small>';
	}

	/**
	 * Render the status column as a colored badge.
	 *
	 * @since 1.1.0
	 *
	 * @param array $item The current item.
	 * @return string
	 */
	protected function column_status( $item ) {
		$status = $this->get_item_value( $item, 'status' );

		// Some API responses use booking_status.
		if ( empty( $status ) ) {
			$status = $this->get_item_value( $item, 'booking_status' );
		}

		if ( empty( $status ) ) {
			$status = 'upcoming';
		}

		$status = strtolower( $status );

		// Map common statuses to labels and CSS classes.
		$status_map = array(
			'upcoming'  => array(
				'label' => __( 'Upcoming', 'gx-zoho-bookings' ),
				'class' => 'gx-zb-badge-upcoming',
			),
			'completed' => array(
				'label' => __( 'Completed', 'gx-zoho-bookings' ),
				'class' => 'gx-zb-badge-completed',
			),
			'cancel'    => array(
				'label' => __( 'Cancelled', 'gx-zoho-bookings' ),
				'class' => 'gx-zb-badge-cancel',
			),
			'cancelled' => array(
				'label' => __( 'Cancelled', 'gx-zoho-bookings' ),
				'class' => 'gx-zb-badge-cancel',
			),
			'noshow'    => array(
				'label' => __( 'No-show', 'gx-zoho-bookings' ),
				'class' => 'gx-zb-badge-noshow',
			),
		);

		$info = isset( $status_map[ $status ] )
			? $status_map[ $status ]
			: array(
				'label' => ucfirst( $status ),
				'class' => 'gx-zb-badge-upcoming',
			);

		return sprintf(
			'<span class="gx-zb-badge %s">%s</span>',
			esc_attr( $info['class'] ),
			esc_html( $info['label'] )
		);
	}

	/**
	 * Render the actions column.
	 *
	 * @since 1.1.0
	 *
	 * @param array $item The current item.
	 * @return string
	 */
	protected function column_actions( $item ) {
		$booking_id = $this->get_item_value( $item, 'booking_id' );
		$status     = strtolower( $this->get_item_value( $item, 'status' ) );
		if ( empty( $status ) ) {
			$status = strtolower( $this->get_item_value( $item, 'booking_status' ) );
		}

		if ( empty( $booking_id ) ) {
			return '—';
		}

		$actions_html = '';

		// Status change actions.
		$status_actions = array();

		if ( 'upcoming' === $status ) {
			$status_actions['completed'] = array(
				'label'   => __( 'Mark Completed', 'gx-zoho-bookings' ),
				'confirm' => __( 'Are you sure you want to mark this appointment as completed?', 'gx-zoho-bookings' ),
			);
			$status_actions['cancel']    = array(
				'label'   => __( 'Cancel', 'gx-zoho-bookings' ),
				'confirm' => __( 'Are you sure you want to cancel this appointment?', 'gx-zoho-bookings' ),
			);
			$status_actions['noshow']    = array(
				'label'   => __( 'No-show', 'gx-zoho-bookings' ),
				'confirm' => __( 'Are you sure you want to mark this appointment as a no-show?', 'gx-zoho-bookings' ),
			);
		} elseif ( 'cancel' === $status || 'cancelled' === $status ) {
			$status_actions['completed'] = array(
				'label'   => __( 'Mark Completed', 'gx-zoho-bookings' ),
				'confirm' => __( 'Are you sure you want to mark this appointment as completed?', 'gx-zoho-bookings' ),
			);
		} elseif ( 'completed' === $status ) {
			$status_actions['cancel'] = array(
				'label'   => __( 'Cancel', 'gx-zoho-bookings' ),
				'confirm' => __( 'Are you sure you want to cancel this completed appointment?', 'gx-zoho-bookings' ),
			);
		} elseif ( 'noshow' === $status ) {
			$status_actions['completed'] = array(
				'label'   => __( 'Mark Completed', 'gx-zoho-bookings' ),
				'confirm' => __( 'Are you sure you want to mark this appointment as completed?', 'gx-zoho-bookings' ),
			);
			$status_actions['cancel']    = array(
				'label'   => __( 'Cancel', 'gx-zoho-bookings' ),
				'confirm' => __( 'Are you sure you want to cancel this appointment?', 'gx-zoho-bookings' ),
			);
		}

		// Build admin-post links for status actions.
		foreach ( $status_actions as $action => $details ) {
			$nonce_action = 'gx_zb_appt_status_' . $booking_id;
			$nonce        = wp_create_nonce( $nonce_action );

			$url = add_query_arg(
				array(
					'action'     => 'gx_zb_appt_status',
					'booking_id' => $booking_id,
					'gx_action'  => $action,
					'_wpnonce'   => $nonce,
				),
				admin_url( 'admin-post.php' )
			);

			$actions_html .= sprintf(
				'<a href="%s" class="gx-zb-action-confirm button button-small" data-confirm="%s" style="margin-right:4px; margin-bottom:4px;">%s</a>',
				esc_url( $url ),
				esc_attr( $details['confirm'] ),
				esc_html( $details['label'] )
			);
		}

		// Reschedule action (link to New Booking page in reschedule mode).
		if ( 'upcoming' === $status ) {
			$service_id = $this->get_item_value( $item, 'service_id' );
			$staff_id   = $this->get_item_value( $item, 'staff_id' );

			$reschedule_url = add_query_arg(
				array(
					'page'       => 'gx-zb-new-booking',
					'reschedule' => $booking_id,
					'service_id' => $service_id,
					'staff_id'   => $staff_id,
				),
				admin_url( 'admin.php' )
			);

			$actions_html .= sprintf(
				'<a href="%s" class="button button-small" style="margin-right:4px; margin-bottom:4px;">%s</a>',
				esc_url( $reschedule_url ),
				esc_html__( 'Reschedule', 'gx-zoho-bookings' )
			);
		}

		return $actions_html;
	}

	/**
	 * Render extra controls between bulk actions and pagination.
	 *
	 * @since 1.1.0
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended — filtering uses GET.
		$current_status = isset( $_GET['gx_zb_status'] ) ? sanitize_text_field( wp_unslash( $_GET['gx_zb_status'] ) ) : '';
		$current_from   = isset( $_GET['gx_zb_from'] ) ? sanitize_text_field( wp_unslash( $_GET['gx_zb_from'] ) ) : '';
		$current_to     = isset( $_GET['gx_zb_to'] ) ? sanitize_text_field( wp_unslash( $_GET['gx_zb_to'] ) ) : '';
		// phpcs:enable

		?>
		<div class="alignleft actions gx-zb-filters">
			<label for="gx_zb_status_filter" class="screen-reader-text">
				<?php esc_html_e( 'Filter by status', 'gx-zoho-bookings' ); ?>
			</label>
			<select name="gx_zb_status" id="gx_zb_status_filter">
				<option value=""><?php esc_html_e( 'All statuses', 'gx-zoho-bookings' ); ?></option>
				<?php
				$statuses = array(
					'upcoming'  => __( 'Upcoming', 'gx-zoho-bookings' ),
					'completed' => __( 'Completed', 'gx-zoho-bookings' ),
					'cancel'    => __( 'Cancelled', 'gx-zoho-bookings' ),
					'noshow'    => __( 'No-show', 'gx-zoho-bookings' ),
				);
				foreach ( $statuses as $value => $label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $value ),
						selected( $current_status, $value, false ),
						esc_html( $label )
					);
				}
				?>
			</select>

			<label for="gx_zb_from_filter" class="screen-reader-text">
				<?php esc_html_e( 'From date', 'gx-zoho-bookings' ); ?>
			</label>
			<input type="date" name="gx_zb_from" id="gx_zb_from_filter" value="<?php echo esc_attr( $current_from ); ?>" placeholder="<?php esc_attr_e( 'From date', 'gx-zoho-bookings' ); ?>" />

			<label for="gx_zb_to_filter" class="screen-reader-text">
				<?php esc_html_e( 'To date', 'gx-zoho-bookings' ); ?>
			</label>
			<input type="date" name="gx_zb_to" id="gx_zb_to_filter" value="<?php echo esc_attr( $current_to ); ?>" placeholder="<?php esc_attr_e( 'To date', 'gx-zoho-bookings' ); ?>" />

			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'gx-zoho-bookings' ); ?>" />

			<?php if ( '' !== $current_status || '' !== $current_from || '' !== $current_to ) : ?>
				<a href="<?php echo esc_url( remove_query_arg( array( 'gx_zb_status', 'gx_zb_from', 'gx_zb_to', 'paged' ) ) ); ?>" class="button">
					<?php esc_html_e( 'Clear', 'gx-zoho-bookings' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Safely retrieve a value from an appointment item array, checking multiple possible keys.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $item The appointment data.
	 * @param string $key  The primary key to look for.
	 * @return string
	 */
	private function get_item_value( $item, $key ) {
		if ( isset( $item[ $key ] ) ) {
			return (string) $item[ $key ];
		}

		// Some API responses may use different keys.
		$fallback_map = array(
			'customer_name'  => array( 'customer_name', 'name' ),
			'customer_email' => array( 'customer_email', 'email' ),
			'service_name'   => array( 'service_name', 'service' ),
			'staff_name'     => array( 'staff_name', 'staff' ),
			'start_time'     => array( 'start_time', 'booked_on', 'from_time' ),
			'duration'       => array( 'duration', 'duration_in_mins' ),
			'booking_id'     => array( 'booking_id', 'id' ),
			'status'         => array( 'status', 'booking_status' ),
		);

		if ( isset( $fallback_map[ $key ] ) ) {
			foreach ( $fallback_map[ $key ] as $alt ) {
				if ( isset( $item[ $alt ] ) && '' !== $item[ $alt ] ) {
					return (string) $item[ $alt ];
				}
			}
		}

		return '';
	}
}