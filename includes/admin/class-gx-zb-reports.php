<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reports dashboard for GX Zoho Bookings.
 *
 * @since 2.0.0
 */
final class GX_ZB_Reports {

    /**
     * Single instance.
     *
     * @since 2.0.0
     * @var GX_ZB_Reports|null
     */
    private static $instance = null;

    /**
     * Private constructor.
     *
     * @since 2.0.0
     */
    private function __construct() {}

    /**
     * Main instance.
     *
     * @since 2.0.0
     * @return GX_ZB_Reports
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Render the reports page.
     *
     * @since 2.0.0
     * @return void
     */
    public function render_page() {
        if ( ! GX_ZB_OAuth::instance()->is_connected() ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Connect to Zoho Bookings first.', 'gx-zoho-bookings' ) . '</p></div>';
            return;
        }

        $dates = $this->daterange_defaults();

        $nonce_verified = false;
        if ( isset( $_GET['_wpnonce_gx_zb_reports'] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce_gx_zb_reports'] ) );
            if ( wp_verify_nonce( $nonce, 'gx_zb_reports' ) ) {
                $nonce_verified = true;
            }
        }

        if ( $nonce_verified && isset( $_GET['gx_zb_from'], $_GET['gx_zb_to'] ) ) {
            $from_raw = sanitize_text_field( wp_unslash( $_GET['gx_zb_from'] ) );
            $to_raw   = sanitize_text_field( wp_unslash( $_GET['gx_zb_to'] ) );
            if ( $from_raw && $to_raw ) {
                $dates['from'] = $from_raw;
                $dates['to']   = $to_raw;
            }
        }

        $statuses = array( 'upcoming', 'completed', 'cancel', 'noshow' );

        $total_bookings     = 0;
        $counts             = array( 'upcoming' => 0, 'completed' => 0, 'cancel' => 0, 'noshow' => 0 );
        $total_revenue      = 0.0;
        $service_revenue    = array(); // [service_name]['bookings' => int, 'revenue' => float]
        $staff_bookings     = array(); // [staff_name => int]
        $errors             = array();

        $api_client = GX_ZB_API_Client::instance();

        foreach ( $statuses as $status ) {
            $filters = array(
                'from_time' => $dates['from'],
                'to_time'   => $dates['to'],
                'status'    => $status,
            );

            $result = $api_client->get_appointments( $filters );

            if ( is_wp_error( $result ) ) {
                $errors[] = $result->get_error_message();
                continue;
            }

            // GX_ZB_API_Client::request() already unwraps the Zoho envelope, so
            // get_appointments() returns the list of rows directly.
            $rows = is_array( $result ) ? $result : array();

            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }

                // Rows fetched under a status filter may omit their own status
                // field — fall back to the status we queried for.
                $row_status = isset( $row['status'] ) ? $row['status'] : $status;
                $total_bookings++;

                if ( isset( $counts[ $row_status ] ) ) {
                    $counts[ $row_status ]++;
                }

                $cost = isset( $row['cost'] ) ? (float) $row['cost'] : 0.0;
                if ( in_array( $row_status, array( 'completed', 'upcoming' ), true ) ) {
                    $total_revenue += $cost;
                }

                $service = isset( $row['service_name'] ) ? $row['service_name'] : __( 'Unknown', 'gx-zoho-bookings' );
                if ( ! isset( $service_revenue[ $service ] ) ) {
                    $service_revenue[ $service ] = array( 'bookings' => 0, 'revenue' => 0.0 );
                }
                $service_revenue[ $service ]['bookings']++;
                if ( in_array( $row_status, array( 'completed', 'upcoming' ), true ) ) {
                    $service_revenue[ $service ]['revenue'] += $cost;
                }

                $staff = isset( $row['staff_name'] ) ? $row['staff_name'] : __( 'Unknown', 'gx-zoho-bookings' );
                if ( ! isset( $staff_bookings[ $staff ] ) ) {
                    $staff_bookings[ $staff ] = 0;
                }
                $staff_bookings[ $staff ]++;
            }
        }

        $currency = strtoupper( GX_ZB_Settings::instance()->get( 'stripe_currency', 'usd' ) );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Reports', 'gx-zoho-bookings' ); ?></h1>

            <?php
            if ( ! empty( $errors ) ) {
                foreach ( $errors as $error ) {
                    echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
                }
            }
            ?>

            <form method="get" action="">
                <?php wp_nonce_field( 'gx_zb_reports', '_wpnonce_gx_zb_reports', false ); ?>
                <input type="hidden" name="page" value="<?php echo isset( $_GET['page'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) : ''; ?>">
                <p>
                    <label for="gx_zb_from"><?php esc_html_e( 'From', 'gx-zoho-bookings' ); ?></label>
                    <input type="text" id="gx_zb_from" name="gx_zb_from" value="<?php echo esc_attr( $dates['from'] ); ?>" placeholder="dd-MMM-yyyy HH:mm:ss" />
                    <label for="gx_zb_to"><?php esc_html_e( 'To', 'gx-zoho-bookings' ); ?></label>
                    <input type="text" id="gx_zb_to" name="gx_zb_to" value="<?php echo esc_attr( $dates['to'] ); ?>" placeholder="dd-MMM-yyyy HH:mm:ss" />
                    <?php submit_button( __( 'Filter', 'gx-zoho-bookings' ), 'secondary', 'submit', false ); ?>
                </p>
            </form>

            <style>
                .gx-zb-report-cards {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 15px;
                    margin: 20px 0;
                }
                .gx-zb-report-card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    padding: 16px;
                    min-width: 150px;
                    box-shadow: 0 1px 1px rgba(0,0,0,0.04);
                }
                .gx-zb-report-card h3 {
                    margin: 0 0 8px;
                    font-size: 14px;
                    color: #555;
                }
                .gx-zb-report-card .value {
                    font-size: 24px;
                    font-weight: 600;
                    color: #1d2327;
                }
            </style>

            <div class="gx-zb-report-cards">
                <div class="gx-zb-report-card">
                    <h3><?php esc_html_e( 'Total Bookings', 'gx-zoho-bookings' ); ?></h3>
                    <div class="value"><?php echo esc_html( $total_bookings ); ?></div>
                </div>
                <div class="gx-zb-report-card">
                    <h3><?php esc_html_e( 'Total Revenue', 'gx-zoho-bookings' ); ?></h3>
                    <div class="value"><?php echo esc_html( strtoupper( $currency ) . ' ' . number_format( $total_revenue, 2 ) ); ?></div>
                </div>
                <div class="gx-zb-report-card">
                    <h3><?php esc_html_e( 'Completed', 'gx-zoho-bookings' ); ?></h3>
                    <div class="value"><?php echo esc_html( $counts['completed'] ); ?></div>
                </div>
                <div class="gx-zb-report-card">
                    <h3><?php esc_html_e( 'Upcoming', 'gx-zoho-bookings' ); ?></h3>
                    <div class="value"><?php echo esc_html( $counts['upcoming'] ); ?></div>
                </div>
            </div>

            <h2><?php esc_html_e( 'Revenue by Service', 'gx-zoho-bookings' ); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Service', 'gx-zoho-bookings' ); ?></th>
                        <th><?php esc_html_e( 'Bookings', 'gx-zoho-bookings' ); ?></th>
                        <th><?php esc_html_e( 'Revenue', 'gx-zoho-bookings' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $service_revenue ) ) : ?>
                        <tr>
                            <td colspan="3"><?php esc_html_e( 'No data available.', 'gx-zoho-bookings' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $service_revenue as $service_name => $data ) : ?>
                            <tr>
                                <td><?php echo esc_html( $service_name ); ?></td>
                                <td><?php echo esc_html( $data['bookings'] ); ?></td>
                                <td><?php echo esc_html( strtoupper( $currency ) . ' ' . number_format( $data['revenue'], 2 ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Bookings by Staff', 'gx-zoho-bookings' ); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Staff', 'gx-zoho-bookings' ); ?></th>
                        <th><?php esc_html_e( 'Bookings', 'gx-zoho-bookings' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $staff_bookings ) ) : ?>
                        <tr>
                            <td colspan="2"><?php esc_html_e( 'No data available.', 'gx-zoho-bookings' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $staff_bookings as $staff_name => $bookings ) : ?>
                            <tr>
                                <td><?php echo esc_html( $staff_name ); ?></td>
                                <td><?php echo esc_html( $bookings ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Get default date range: first day of current month 00:00:00 to today 23:59:59.
     *
     * @since 2.0.0
     * @return array<string,string> [ 'from' => 'dd-MMM-yyyy HH:mm:ss', 'to' => 'dd-MMM-yyyy HH:mm:ss' ]
     */
    private function daterange_defaults() {
        $now_ts = current_time( 'timestamp' );

        $first_day = wp_date( 'Y-m-01', $now_ts );
        $today     = wp_date( 'Y-m-d', $now_ts );

        $from_ts = strtotime( $first_day . ' 00:00:00' );
        $to_ts   = strtotime( $today . ' 23:59:59' );

        return array(
            'from' => wp_date( 'd-M-Y H:i:s', $from_ts ),
            'to'   => wp_date( 'd-M-Y H:i:s', $to_ts ),
        );
    }
}
