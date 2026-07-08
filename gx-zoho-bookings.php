<?php
/**
 * Plugin Name:       GX Zoho Bookings
 * Plugin URI:        https://genexmarketing.com/plugins/gx-zoho-bookings/
 * Description:       Connect Zoho Bookings to WordPress. Embed your booking page or connect via OAuth to display services and manage appointments — dashboard, booking creation, reschedule and status updates — right inside wp-admin. Free-plan friendly.
 * Version:           1.9.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Genex Marketing Agency Ltd
 * Author URI:        https://genexmarketing.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gx-zoho-bookings
 * Domain Path:       /languages
 *
 * @package GX_Zoho_Bookings
 */

defined( 'ABSPATH' ) || exit;

define( 'GX_ZB_VERSION', '1.9.0' );
define( 'GX_ZB_FILE', __FILE__ );
define( 'GX_ZB_DIR', plugin_dir_path( __FILE__ ) );
define( 'GX_ZB_URL', plugin_dir_url( __FILE__ ) );
define( 'GX_ZB_OPTION_SETTINGS', 'gx_zb_settings' );
define( 'GX_ZB_OPTION_TOKENS', 'gx_zb_tokens' );

require_once GX_ZB_DIR . 'includes/class-gx-zb-regions.php';
require_once GX_ZB_DIR . 'includes/class-gx-zb-settings.php';
require_once GX_ZB_DIR . 'includes/class-gx-zb-oauth.php';
require_once GX_ZB_DIR . 'includes/class-gx-zb-api-client.php';
require_once GX_ZB_DIR . 'includes/class-gx-zb-admin.php';
require_once GX_ZB_DIR . 'includes/class-gx-zb-shortcodes.php';
require_once GX_ZB_DIR . 'includes/class-gx-zb-blocks.php';
require_once GX_ZB_DIR . 'includes/class-gx-zb-stripe.php';
require_once GX_ZB_DIR . 'includes/class-gx-zb-staff-meta.php';
require_once GX_ZB_DIR . 'includes/class-gx-zb-calendar.php';
require_once GX_ZB_DIR . 'includes/class-gx-zb-payments.php';
require_once GX_ZB_DIR . 'includes/class-gx-zb-service-pages.php';
require_once GX_ZB_DIR . 'includes/admin/class-gx-zb-admin-bar.php';
require_once GX_ZB_DIR . 'includes/class-gx-zb-mcp-server.php';

// Management screens only exist inside wp-admin (admin-ajax included), so
// skip loading them — and the WP_List_Table dependency — on the front end.
if ( is_admin() ) {
	require_once GX_ZB_DIR . 'includes/admin/class-gx-zb-appointments-table.php';
	require_once GX_ZB_DIR . 'includes/admin/class-gx-zb-manage.php';
	require_once GX_ZB_DIR . 'includes/admin/class-gx-zb-booking-form.php';
	require_once GX_ZB_DIR . 'includes/admin/class-gx-zb-services-admin.php';
	require_once GX_ZB_DIR . 'includes/admin/class-gx-zb-staff-admin.php';
}

require_once GX_ZB_DIR . 'includes/class-gx-zb-plugin.php';

/**
 * Return the core plugin instance.
 *
 * @return GX_ZB_Plugin
 */
function gx_zb() {
	return GX_ZB_Plugin::instance();
}

add_action( 'plugins_loaded', 'gx_zb' );

register_activation_hook( __FILE__, array( 'GX_ZB_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GX_ZB_Plugin', 'deactivate' ) );
