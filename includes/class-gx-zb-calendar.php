<?php
/**
 * Calendar utility class for GX Zoho Bookings.
 *
 * @package GX_Zoho_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Final class providing static methods for generating calendar links and ICS data.
 */
final class GX_ZB_Calendar {

	/**
	 * Generate a Google Calendar template URL.
	 *
	 * @param array $event {
	 *     Event data.
	 *
	 *     @type string $title        Event title.
	 *     @type string $start        Start date/time in 'Y-m-d H:i:s' (site local time).
	 *     @type int    $duration_min Duration in minutes.
	 *     @type string $description  Optional. Event description.
	 *     @type string $location     Optional. Event location.
	 * }
	 * @return string URL string, or empty string if invalid start.
	 */
	public static function google_url( array $event ) {
		if ( empty( $event['title'] ) || empty( $event['start'] ) || ! isset( $event['duration_min'] ) ) {
			return '';
		}

		$tz_string = wp_timezone_string();
		try {
			$start_dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $event['start'], new DateTimeZone( $tz_string ) );
			if ( false === $start_dt ) {
				return '';
			}
		} catch ( Exception $e ) {
			return '';
		}

		$end_dt = clone $start_dt;
		$end_dt->modify( '+ ' . (int) $event['duration_min'] . ' minutes' );

		$start_str = $start_dt->format( 'Ymd\THis' );
		$end_str   = $end_dt->format( 'Ymd\THis' );

		// Build the query by hand with rawurlencode — add_query_arg() does not
		// encode values, so an '&' inside a title/site name would truncate the
		// details parameter on Google's side.
		$url = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
			. '&text=' . rawurlencode( $event['title'] )
			. '&dates=' . rawurlencode( $start_str . '/' . $end_str );

		if ( ! empty( $event['description'] ) ) {
			$url .= '&details=' . rawurlencode( $event['description'] );
		}
		if ( ! empty( $event['location'] ) ) {
			$url .= '&location=' . rawurlencode( $event['location'] );
		}
		// Google's ctz only accepts IANA zone names (e.g. America/Vancouver) —
		// offset-style strings like "+00:00" would 400, so omit them.
		if ( false !== strpos( $tz_string, '/' ) ) {
			$url .= '&ctz=' . rawurlencode( $tz_string );
		}

		return $url;
	}

	/**
	 * Generate full ICS (iCalendar) content.
	 *
	 * @param array $event Same shape as google_url().
	 * @return string ICS content, or empty string if invalid start.
	 */
	public static function ics_content( array $event ) {
		if ( empty( $event['title'] ) || empty( $event['start'] ) || ! isset( $event['duration_min'] ) ) {
			return '';
		}

		$tz_string = wp_timezone_string();
		try {
			$start_dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $event['start'], new DateTimeZone( $tz_string ) );
			if ( false === $start_dt ) {
				return '';
			}
		} catch ( Exception $e ) {
			return '';
		}

		$end_dt = clone $start_dt;
		$end_dt->modify( '+ ' . (int) $event['duration_min'] . ' minutes' );

		$dtstart = $start_dt->format( 'Ymd\THis' );
		$dtend   = $end_dt->format( 'Ymd\THis' );
		$dtstamp = $start_dt->format( 'Ymd\THis' ); // Derive from start time as allowed.

		$uid = md5( home_url() . $event['title'] . $event['start'] ) . '@gx-zoho-bookings';

		// Escape text values per RFC 5545: backslash, semicolon, comma, newlines.
		$esc = function ( $text ) {
			$text = str_replace( '\\', '\\\\', $text );
			$text = str_replace( ';', '\\;', $text );
			$text = str_replace( ',', '\\,', $text );
			$text = str_replace( "\n", '\\n', $text );
			return $text;
		};

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//GX Zoho Bookings//EN',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . $dtstamp,
			'DTSTART:' . $dtstart,
			'DTEND:' . $dtend,
			'SUMMARY:' . $esc( $event['title'] ),
		);

		if ( ! empty( $event['description'] ) ) {
			$lines[] = 'DESCRIPTION:' . $esc( $event['description'] );
		}

		if ( ! empty( $event['location'] ) ) {
			$lines[] = 'LOCATION:' . $esc( $event['location'] );
		}

		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", $lines );
	}

	/**
	 * Return a data URI for an ICS file.
	 *
	 * @param array $event Same shape as google_url().
	 * @return string Data URI or empty string if ICS content is empty.
	 */
	public static function ics_data_uri( array $event ) {
		$content = self::ics_content( $event );
		return ( '' === $content ) ? '' : 'data:text/calendar;charset=utf-8,' . rawurlencode( $content );
	}

	/**
	 * Render a confirmation panel with calendar links.
	 *
	 * @param array  $event            Event data (same shape as google_url()).
	 * @param string $message          Confirmation message (plain text; escaped on output).
	 * @param string $book_another_url Optional. URL for booking another appointment.
	 * @return string Escaped HTML.
	 */
	public static function confirmation_panel( array $event, $message, $book_another_url = '' ) {
		$google_url   = self::google_url( $event );
		$ics_data_uri = self::ics_data_uri( $event );

		$html  = '<div class="gx-zb-confirmation">';
		$html .= '<div class="gx-zb-book-result success">' . esc_html( $message ) . '</div>';

		// Build details section.
		$html .= '<div class="gx-zb-conf-details">';
		if ( ! empty( $event['title'] ) ) {
			$html .= '<strong>' . esc_html( $event['title'] ) . '</strong><br>';
		}

		if ( ! empty( $event['start'] ) && isset( $event['duration_min'] ) ) {
			$tz_string = wp_timezone_string();
			try {
				$start_dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $event['start'], new DateTimeZone( $tz_string ) );
				if ( false !== $start_dt ) {
					$timestamp = $start_dt->getTimestamp();
					$date_time = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
					$html     .= esc_html( $date_time );
					$html     .= ' (' . (int) $event['duration_min'] . ' min)';
				}
			} catch ( Exception $e ) {
				// Skip date line on error.
			}
		}
		// Staff video-conference link, when one is configured.
		if ( ! empty( $event['video_url'] ) ) {
			$html .= '<p class="gx-zb-conf-video"><a class="gx-zb-cal-btn gx-zb-video-btn" href="' . esc_url( $event['video_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Join video call', 'gx-zoho-bookings' ) . '</a></p>';
		}

		$html .= '</div>'; // .gx-zb-conf-details

		// Calendar buttons.
		if ( '' !== $google_url || '' !== $ics_data_uri ) {
			$html .= '<p class="gx-zb-cal-lead">' . esc_html__( 'Save your appointment to your calendar below.', 'gx-zoho-bookings' ) . '</p>';
			$html .= '<div class="gx-zb-cal-buttons">';
			if ( '' !== $google_url ) {
				$html .= '<a class="gx-zb-cal-btn gx-zb-cal-google" href="' . esc_url( $google_url ) . '" target="_blank" rel="noopener noreferrer">';
				$html .= esc_html__( 'Add to Google Calendar', 'gx-zoho-bookings' ) . '</a> ';
			}
			if ( '' !== $ics_data_uri ) {
				$html .= '<a class="gx-zb-cal-btn gx-zb-cal-ics" href="' . esc_attr( $ics_data_uri ) . '" download="appointment.ics">';
				$html .= esc_html__( 'Add to Apple / Outlook (.ics)', 'gx-zoho-bookings' ) . '</a>';
			}
			$html .= '</div>';
		}

		// Book another link.
		if ( '' !== $book_another_url ) {
			$html .= '<p class="gx-zb-book-another"><a href="' . esc_url( $book_another_url ) . '">';
			$html .= esc_html__( 'Book another appointment', 'gx-zoho-bookings' ) . '</a></p>';
		}

		$html .= '</div>'; // .gx-zb-confirmation

		return $html;
	}
}
