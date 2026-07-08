<?php
defined( 'ABSPATH' ) || exit;

/**
 * Site-side staff metadata.
 *
 * The Zoho Bookings API has no staff update/delete endpoints and no
 * video-conference field on staff (verified 2026-07-08), so both live in
 * WordPress options:
 *  - gx_zb_staff_meta   : map of staff_id => array( 'video_url' => string )
 *  - gx_zb_staff_hidden : list of staff_ids removed from booking on this site
 *
 * Hiding a staff member only affects this website's booking UI; the Zoho
 * account itself is managed in the Zoho Bookings admin.
 *
 * @package GX_Zoho_Bookings
 * @since 1.9.0
 */
final class GX_ZB_Staff_Meta {

	const OPTION_META   = 'gx_zb_staff_meta';
	const OPTION_HIDDEN = 'gx_zb_staff_hidden';

	/**
	 * Returns the saved video-conference URL for a staff member.
	 *
	 * @param string $staff_id Staff id.
	 * @return string URL or ''.
	 */
	public static function video_url( $staff_id ) {
		$staff_id = (string) $staff_id;
		if ( '' === $staff_id ) {
			return '';
		}
		$meta = get_option( self::OPTION_META, array() );
		if ( is_array( $meta ) && isset( $meta[ $staff_id ]['video_url'] ) ) {
			return (string) $meta[ $staff_id ]['video_url'];
		}
		return '';
	}

	/**
	 * Saves (or clears, when empty) the video-conference URL for a staff member.
	 *
	 * @param string $staff_id Staff id.
	 * @param string $url      Video call URL; empty string clears it.
	 * @return void
	 */
	public static function set_video_url( $staff_id, $url ) {
		$staff_id = (string) $staff_id;
		if ( '' === $staff_id ) {
			return;
		}
		$meta = get_option( self::OPTION_META, array() );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			unset( $meta[ $staff_id ] );
		} else {
			$meta[ $staff_id ]              = isset( $meta[ $staff_id ] ) && is_array( $meta[ $staff_id ] ) ? $meta[ $staff_id ] : array();
			$meta[ $staff_id ]['video_url'] = $url;
		}
		update_option( self::OPTION_META, $meta, 'no' );
	}

	/**
	 * Returns the list of hidden staff ids.
	 *
	 * @return string[]
	 */
	public static function hidden_ids() {
		$hidden = get_option( self::OPTION_HIDDEN, array() );
		if ( ! is_array( $hidden ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $hidden ) ) );
	}

	/**
	 * Whether a staff member is hidden from booking on this site.
	 *
	 * @param string $staff_id Staff id.
	 * @return bool
	 */
	public static function is_hidden( $staff_id ) {
		return in_array( (string) $staff_id, self::hidden_ids(), true );
	}

	/**
	 * Hides a staff member from booking on this site.
	 *
	 * @param string $staff_id Staff id.
	 * @return void
	 */
	public static function hide( $staff_id ) {
		$staff_id = (string) $staff_id;
		if ( '' === $staff_id ) {
			return;
		}
		$hidden = self::hidden_ids();
		if ( ! in_array( $staff_id, $hidden, true ) ) {
			$hidden[] = $staff_id;
			update_option( self::OPTION_HIDDEN, $hidden, 'no' );
		}
	}

	/**
	 * Restores a hidden staff member.
	 *
	 * @param string $staff_id Staff id.
	 * @return void
	 */
	public static function unhide( $staff_id ) {
		$staff_id = (string) $staff_id;
		$hidden   = self::hidden_ids();
		$new      = array_values( array_diff( $hidden, array( $staff_id ) ) );
		if ( count( $new ) !== count( $hidden ) ) {
			update_option( self::OPTION_HIDDEN, $new, 'no' );
		}
	}

	/**
	 * Filters a staff rows array, dropping hidden members.
	 *
	 * Rows may use 'id' or 'staff_id' as their identifier key.
	 *
	 * @param array $staff_rows Staff rows from the API client.
	 * @return array
	 */
	public static function filter_visible( $staff_rows ) {
		if ( ! is_array( $staff_rows ) ) {
			return array();
		}
		$hidden = self::hidden_ids();
		if ( empty( $hidden ) ) {
			return $staff_rows;
		}
		$visible = array();
		foreach ( $staff_rows as $row ) {
			$sid = '';
			if ( is_array( $row ) ) {
				$sid = isset( $row['id'] ) ? (string) $row['id'] : ( isset( $row['staff_id'] ) ? (string) $row['staff_id'] : '' );
			}
			if ( '' !== $sid && in_array( $sid, $hidden, true ) ) {
				continue;
			}
			$visible[] = $row;
		}
		return $visible;
	}
}
