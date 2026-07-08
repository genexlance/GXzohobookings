<?php
/**
 * Settings access layer.
 *
 * All reads/writes of the gx_zb_settings option go through this class so
 * sanitization rules live in exactly one place.
 *
 * @package GX_Zoho_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Options wrapper for plugin settings.
 */
final class GX_ZB_Settings {

	/**
	 * Singleton instance.
	 *
	 * @var GX_ZB_Settings|null
	 */
	private static $instance = null;

	/**
	 * Get the shared instance.
	 *
	 * @return GX_ZB_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mode'          => 'embed',
			'region'        => 'us',
			'embed_url'     => '',
			'client_id'     => '',
			'client_secret' => '',
			'workspace_id'  => '',
			'cache_minutes'    => 15,
			'mcp_enabled'      => false,
			'mcp_api_key'      => '',
			'payments_enabled' => false,
			'stripe_pk'        => '',
			'stripe_sk'        => '',
			'stripe_currency'  => 'usd',
			'services_css'     => '',
		);
	}

	/**
	 * Full settings array merged with defaults.
	 *
	 * @return array
	 */
	public function all() {
		$saved = get_option( GX_ZB_OPTION_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when key is unknown.
	 * @return mixed
	 */
	public function get( $key, $default = '' ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	/**
	 * Sanitize, merge and persist a partial settings array.
	 *
	 * @param array $partial Keys to update.
	 * @return void
	 */
	public function update( array $partial ) {
		$merged = array_merge( $this->all(), $partial );
		update_option( GX_ZB_OPTION_SETTINGS, $this->sanitize( $merged ) );
	}

	/**
	 * Sanitize a raw settings array. Registered as the Settings API
	 * sanitize_callback, so it must tolerate arbitrary input.
	 *
	 * @param array $raw Raw input.
	 * @return array Clean settings.
	 */
	public function sanitize( $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$current = get_option( GX_ZB_OPTION_SETTINGS, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$current = wp_parse_args( $current, self::defaults() );
		$clean   = self::defaults();

		$mode          = isset( $raw['mode'] ) ? sanitize_key( $raw['mode'] ) : $current['mode'];
		$clean['mode'] = in_array( $mode, array( 'embed', 'api' ), true ) ? $mode : 'embed';

		$region          = isset( $raw['region'] ) ? sanitize_key( $raw['region'] ) : $current['region'];
		$clean['region'] = GX_ZB_Regions::is_valid( $region ) ? $region : 'us';

		$clean['embed_url']    = isset( $raw['embed_url'] ) ? esc_url_raw( trim( (string) $raw['embed_url'] ) ) : $current['embed_url'];
		$clean['client_id']    = isset( $raw['client_id'] ) ? sanitize_text_field( $raw['client_id'] ) : $current['client_id'];
		$clean['workspace_id'] = isset( $raw['workspace_id'] ) ? sanitize_text_field( $raw['workspace_id'] ) : $current['workspace_id'];

		// Blank secret submission means "keep the stored secret" — the admin
		// form renders a masked placeholder rather than the real value.
		$secret                 = isset( $raw['client_secret'] ) ? sanitize_text_field( $raw['client_secret'] ) : '';
		$clean['client_secret'] = ( '' === $secret ) ? $current['client_secret'] : $secret;

		$minutes                = isset( $raw['cache_minutes'] ) ? absint( $raw['cache_minutes'] ) : $current['cache_minutes'];
		$clean['cache_minutes'] = max( 1, min( 1440, $minutes ? $minutes : 15 ) );

		// MCP: checkbox toggles; the API key is never accepted from form input —
		// it is only (re)generated server-side by the regen handler.
		$clean['mcp_enabled'] = ! empty( $raw['mcp_enabled'] );
		$clean['mcp_api_key'] = isset( $current['mcp_api_key'] ) ? (string) $current['mcp_api_key'] : '';

		// Stripe payments.
		$clean['payments_enabled'] = ! empty( $raw['payments_enabled'] );
		$clean['stripe_pk']        = isset( $raw['stripe_pk'] ) ? sanitize_text_field( $raw['stripe_pk'] ) : $current['stripe_pk'];

		// Secret key: blank submit keeps the stored value (masked in admin).
		$sk                  = isset( $raw['stripe_sk'] ) ? sanitize_text_field( $raw['stripe_sk'] ) : '';
		$clean['stripe_sk']  = ( '' === $sk ) ? $current['stripe_sk'] : $sk;

		$currency = isset( $raw['stripe_currency'] ) ? strtolower( preg_replace( '/[^a-zA-Z]/', '', (string) $raw['stripe_currency'] ) ) : $current['stripe_currency'];
		$clean['stripe_currency'] = ( 3 === strlen( $currency ) ) ? $currency : 'usd';

		// Custom services-block CSS: strip any markup, keep the stylesheet text.
		$clean['services_css'] = isset( $raw['services_css'] ) ? trim( wp_strip_all_tags( (string) $raw['services_css'] ) ) : $current['services_css'];

		return $clean;
	}

	/**
	 * Sanitize while accepting a server-generated MCP key. Used only by the
	 * key-regeneration handler — the Settings API path must keep ignoring
	 * form-supplied keys.
	 *
	 * @param array $raw Settings including a freshly generated mcp_api_key.
	 * @return array Clean settings.
	 */
	public function sanitize_with_key( array $raw ) {
		$clean = $this->sanitize( $raw );
		if ( isset( $raw['mcp_api_key'] ) ) {
			$clean['mcp_api_key'] = preg_replace( '/[^A-Za-z0-9]/', '', (string) $raw['mcp_api_key'] );
		}
		return $clean;
	}
}
