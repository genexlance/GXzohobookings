<?php
/**
 * Uninstall cleanup: remove every option and transient the plugin created.
 *
 * @package GX_Zoho_Bookings
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'gx_zb_settings' );
delete_option( 'gx_zb_tokens' );
delete_option( 'gx_zb_needs_reconnect' );

// Cached API responses. Keys are tracked in gx_zb_transient_keys by the API client.
$gx_zb_keys = get_option( 'gx_zb_transient_keys', array() );
if ( is_array( $gx_zb_keys ) ) {
	foreach ( $gx_zb_keys as $gx_zb_key ) {
		delete_transient( $gx_zb_key );
	}
}
delete_option( 'gx_zb_transient_keys' );

// Site-side staff metadata + landing-page map (v1.5.0+ / v1.9.0+).
delete_option( 'gx_zb_service_pages' );
delete_option( 'gx_zb_staff_meta' );
delete_option( 'gx_zb_staff_hidden' );

// Per-service custom field definitions (v2.0.0).
delete_option( 'gx_zb_service_fields' );
