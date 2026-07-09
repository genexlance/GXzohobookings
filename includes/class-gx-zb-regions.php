<?php
/**
 * Zoho data-center region map.
 *
 * Single source of truth for Zoho domains. No other class may hardcode
 * a Zoho hostname — everything routes through this map so new regions
 * are a one-line change.
 *
 * @package GX_Zoho_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static region → domain resolver.
 */
final class GX_ZB_Regions {

	/**
	 * Region definitions keyed by region slug.
	 *
	 * @return array[]
	 */
	private static function map() {
		return array(
			'us' => array(
				'label'    => __( 'United States (zoho.com)', 'gx-zoho-bookings' ),
				'accounts' => 'https://accounts.zoho.com',
				'api'      => 'https://www.zohoapis.com',
			),
			'eu' => array(
				'label'    => __( 'Europe (zoho.eu)', 'gx-zoho-bookings' ),
				'accounts' => 'https://accounts.zoho.eu',
				'api'      => 'https://www.zohoapis.eu',
			),
			'in' => array(
				'label'    => __( 'India (zoho.in)', 'gx-zoho-bookings' ),
				'accounts' => 'https://accounts.zoho.in',
				'api'      => 'https://www.zohoapis.in',
			),
			'au' => array(
				'label'    => __( 'Australia (zoho.com.au)', 'gx-zoho-bookings' ),
				'accounts' => 'https://accounts.zoho.com.au',
				'api'      => 'https://www.zohoapis.com.au',
			),
			'jp' => array(
				'label'    => __( 'Japan (zoho.jp)', 'gx-zoho-bookings' ),
				'accounts' => 'https://accounts.zoho.jp',
				'api'      => 'https://www.zohoapis.jp',
			),
			'ca' => array(
				'label'    => __( 'Canada (zohocloud.ca)', 'gx-zoho-bookings' ),
				'accounts' => 'https://accounts.zohocloud.ca',
				'api'      => 'https://www.zohoapis.ca',
			),
			'cn' => array(
				'label'    => __( 'China (zoho.com.cn)', 'gx-zoho-bookings' ),
				'accounts' => 'https://accounts.zoho.com.cn',
				'api'      => 'https://www.zohoapis.com.cn',
			),
		);
	}

	/**
	 * OAuth accounts server base URL for a region.
	 *
	 * @param string $region Region slug.
	 * @return string Base URL, or US fallback for unknown regions.
	 */
	public static function accounts_base( $region ) {
		$map = self::map();
		return isset( $map[ $region ] ) ? $map[ $region ]['accounts'] : $map['us']['accounts'];
	}

	/**
	 * API server base URL for a region.
	 *
	 * @param string $region Region slug.
	 * @return string Base URL, or US fallback for unknown regions.
	 */
	public static function api_base( $region ) {
		$map = self::map();
		return isset( $map[ $region ] ) ? $map[ $region ]['api'] : $map['us']['api'];
	}

	/**
	 * Zoho CRM v6 REST base URL for a region.
	 *
	 * CRM shares the region's zohoapis domain; only the path differs.
	 *
	 * @since 2.0.0
	 * @param string $region Region slug.
	 * @return string Base URL ending in a slash, e.g. https://www.zohoapis.com/crm/v6/.
	 */
	public static function crm_base( $region ) {
		return trailingslashit( self::api_base( $region ) ) . 'crm/v6/';
	}

	/**
	 * Region choices for the settings select field.
	 *
	 * @return array<string,string> slug => label.
	 */
	public static function choices() {
		$choices = array();
		foreach ( self::map() as $slug => $region ) {
			$choices[ $slug ] = $region['label'];
		}
		return $choices;
	}

	/**
	 * Whether a region slug is recognised.
	 *
	 * @param string $region Region slug.
	 * @return bool
	 */
	public static function is_valid( $region ) {
		$map = self::map();
		return isset( $map[ $region ] );
	}

	/**
	 * Hostnames considered valid Zoho Bookings embed sources.
	 *
	 * Used to validate admin-supplied embed URLs before rendering an iframe.
	 *
	 * @return string[] Hostname suffixes.
	 */
	public static function embed_host_suffixes() {
		return array(
			'zohobookings.com',
			'zohobookings.eu',
			'zohobookings.in',
			'zohobookings.com.au',
			'zohobookings.jp',
			'zohobookings.ca',
			'zohobookings.com.cn',
			'zoho.com',
			'zoho.eu',
			'zoho.in',
			'zoho.com.au',
			'zoho.jp',
			'zohocloud.ca',
			'zoho.com.cn',
		);
	}
}
