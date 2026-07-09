<?php
/**
 * Core plugin wiring.
 *
 * @package GX_Zoho_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots every component exactly once.
 */
final class GX_ZB_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var GX_ZB_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and on first call boot) the plugin.
	 *
	 * @return GX_ZB_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Wire components to WordPress hooks.
	 *
	 * @return void
	 */
	private function boot() {
		load_plugin_textdomain( 'gx-zoho-bookings', false, dirname( plugin_basename( GX_ZB_FILE ) ) . '/languages' );

		GX_ZB_Shortcodes::instance()->register();
		GX_ZB_Blocks::instance()->register();
		GX_ZB_Payments::instance()->register();
		GX_ZB_Service_Pages::instance()->register();

		if ( is_admin() ) {
			GX_ZB_Admin::instance()->register_hooks();
			GX_ZB_Manage::instance()->register_hooks();
			GX_ZB_Booking_Form::instance()->register_hooks();
			GX_ZB_Services_Admin::instance()->register_hooks();
			GX_ZB_Staff_Admin::instance()->register_hooks();
			GX_ZB_Fields_Admin::instance()->register_hooks();
			add_action( 'admin_init', array( GX_ZB_OAuth::instance(), 'handle_callback' ) );
		}

		// Admin-bar quick menu renders on the front end too, so register it
		// outside the is_admin() branch.
		GX_ZB_Admin_Bar::instance()->register_hooks();

		// MCP endpoint lives on the REST API, which runs outside wp-admin.
		GX_ZB_MCP_Server::instance()->register_hooks();

		// FUTURE (paid plan): register REST routes for front-end booking
		// creation and live slot pickers once those ship.
	}

	/**
	 * Activation: seed defaults so the settings screen renders populated.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( GX_ZB_OPTION_SETTINGS, false ) ) {
			add_option( GX_ZB_OPTION_SETTINGS, GX_ZB_Settings::defaults() );
		}
	}

	/**
	 * Deactivation: drop cached API responses; keep settings and tokens so a
	 * re-activate does not force a reconnect. Full cleanup lives in uninstall.php.
	 *
	 * @return void
	 */
	public static function deactivate() {
		GX_ZB_API_Client::instance()->flush_cache();
	}
}
