<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes handler for GX Zoho Bookings.
 *
 * @package GX_Zoho_Bookings
 * @since   1.0.0
 */

final class GX_ZB_Shortcodes {

	/**
	 * Singleton instance.
	 *
	 * @var GX_ZB_Shortcodes
	 */
	private static $instance = null;

	/**
	 * Get the single instance of the class.
	 *
	 * @return GX_ZB_Shortcodes
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct creation.
	 */
	private function __construct() {}

	/**
	 * Register shortcodes and enqueue hooks.
	 */
	public function register() {
		add_shortcode( 'zoho_bookings_embed', array( $this, 'embed_shortcode' ) );
		add_shortcode( 'zoho_bookings_services', array( $this, 'services_shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * [zoho_bookings_embed] shortcode callback.
	 *
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Shortcode enclosed content (unused).
	 * @return string Rendered HTML.
	 */
	public function embed_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'url'    => '',
				'height' => '750',
			),
			$atts,
			'zoho_bookings_embed'
		);

		return self::render_embed( $atts );
	}

	/**
	 * [zoho_bookings_services] shortcode callback.
	 *
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Shortcode enclosed content (unused).
	 * @return string Rendered HTML.
	 */
	public function services_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'workspace'        => '',
				'columns'          => '3',
				'show_description' => 'yes',
				'show_duration'    => 'yes',
				'book_label'       => '',
				'pay_label'        => '',
			),
			$atts,
			'zoho_bookings_services'
		);

		return self::render_services( $atts );
	}

	/**
	 * Enqueue frontend styles only when required.
	 */
	public function enqueue_frontend_assets() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$content = $post->post_content;
		$has_shortcode = has_shortcode( $content, 'zoho_bookings_embed' )
						|| has_shortcode( $content, 'zoho_bookings_services' );
		$has_block     = function_exists( 'has_block' )
						&& ( has_block( 'gx-zoho-bookings/embed', $post ) || has_block( 'gx-zoho-bookings/services', $post ) );

		if ( ! $has_shortcode && ! $has_block ) {
			return;
		}

		wp_enqueue_style(
			'gx-zb-frontend',
			GX_ZB_URL . 'assets/css/gx-zb-frontend.css',
			array(),
			GX_ZB_VERSION
		);
		self::add_custom_css();
	}

	/**
	 * Enqueue the front-end stylesheet at render time. Covers block themes
	 * and widget areas where the is_singular() content scan never runs.
	 */
	private static function enqueue_frontend_style() {
		if ( ! wp_style_is( 'gx-zb-frontend', 'enqueued' ) ) {
			wp_enqueue_style(
				'gx-zb-frontend',
				GX_ZB_URL . 'assets/css/gx-zb-frontend.css',
				array(),
				GX_ZB_VERSION
			);
		}
		self::add_custom_css();
	}

	/**
	 * Attach the site-owner's custom services-block CSS (Settings → Services
	 * block → Custom CSS) to the front-end stylesheet, once per request.
	 */
	private static function add_custom_css() {
		static $added = false;
		if ( $added ) {
			return;
		}
		$added = true;

		$custom = (string) GX_ZB_Settings::instance()->get( 'services_css' );
		if ( '' !== trim( $custom ) ) {
			wp_add_inline_style( 'gx-zb-frontend', wp_strip_all_tags( $custom ) );
		}
	}

	/**
	 * Shared render method for the embed shortcode and block.
	 *
	 * @param array $atts Render attributes.
	 * @return string HTML output.
	 */
	public static function render_embed( $atts ) {
		self::enqueue_frontend_style();

		$url    = isset( $atts['url'] ) ? esc_url_raw( $atts['url'] ) : '';
		$height = isset( $atts['height'] ) ? absint( $atts['height'] ) : 750;
		if ( $height < 1 ) {
			$height = 750;
		}

		// Fallback to plugin settings embed URL.
		if ( empty( $url ) ) {
			$settings = GX_ZB_Settings::instance();
			$url      = $settings->get( 'embed_url' );
		}

		if ( empty( $url ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="gx-zb-error">' . esc_html__( 'No Zoho Bookings embed URL provided.', 'gx-zoho-bookings' ) . '</p>';
			}
			return '';
		}

		// Validate URL host.
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) || ! self::is_valid_booking_url( $url ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="gx-zb-error">' . esc_html__( 'Invalid Zoho Bookings embed URL.', 'gx-zoho-bookings' ) . '</p>';
			}
			return '';
		}

		$title = esc_attr__( 'Zoho Bookings', 'gx-zoho-bookings' );

		return sprintf(
			'<div class="gx-zb-embed"><iframe src="%s" height="%d" title="%s" loading="lazy" style="width:100%%;border:0;"></iframe></div>',
			esc_url( $url ),
			$height,
			$title
		);
	}

	/**
	 * Check whether the given URL is a known Zoho Bookings page.
	 *
	 * @param string $url Full URL.
	 * @return bool
	 */
	private static function is_valid_booking_url( $url ) {
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return false;
		}

		$host = strtolower( $parsed['host'] );

		// Known standalone booking domains — match the bare domain or any
		// subdomain (booking pages live at {business}.zohobookings.com).
		$known_domains = array(
			'zohobookings.com',
			'zohobookings.eu',
			'zohobookings.in',
			'zohobookings.com.au',
			'zohobookings.jp',
			'zohobookings.ca',
			'zohobookings.com.cn',
		);

		foreach ( $known_domains as $domain ) {
			if ( $host === $domain || '.' . $domain === substr( $host, -strlen( $domain ) - 1 ) ) {
				return true;
			}
		}

		// Pattern: {name}.zoho.{tld}/bookings — hostnames verified against the
		// explicit region allowlist; a regex here would accept zoho.evil.com.
		$path = isset( $parsed['path'] ) ? $parsed['path'] : '';
		if ( 0 === strpos( $path, '/bookings' ) ) {
			foreach ( GX_ZB_Regions::embed_host_suffixes() as $domain ) {
				if ( $host === $domain || '.' . $domain === substr( $host, -strlen( $domain ) - 1 ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Shared render method for the services shortcode and block.
	 *
	 * @param array $atts Render attributes.
	 * @return string HTML output.
	 */
	public static function render_services( $atts ) {
		self::enqueue_frontend_style();

		$settings = GX_ZB_Settings::instance();

		if ( 'api' !== $settings->get( 'mode' ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="gx-zb-error">' . esc_html__( 'Please enable API mode in the plugin settings to display services.', 'gx-zoho-bookings' ) . '</p>';
			}
			return '';
		}

		$workspace_id = ! empty( $atts['workspace'] ) ? sanitize_text_field( $atts['workspace'] ) : $settings->get( 'workspace_id' );

		$columns = isset( $atts['columns'] ) ? absint( $atts['columns'] ) : 3;
		$columns = max( 1, min( 4, $columns ) );

		$show_description = in_array( strtolower( (string) ( $atts['show_description'] ?? 'yes' ) ), array( 'yes', 'true', '1' ), true );
		$show_duration    = in_array( strtolower( (string) ( $atts['show_duration'] ?? 'yes' ) ), array( 'yes', 'true', '1' ), true );

		$api_client = GX_ZB_API_Client::instance();
		$services   = $api_client->get_services( $workspace_id );

		if ( is_wp_error( $services ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				/* translators: %s: error message from the API client */
				return '<p class="gx-zb-error">' . sprintf( esc_html__( 'Booking services could not be loaded: %s', 'gx-zoho-bookings' ), esc_html( $services->get_error_message() ) ) . '</p>';
			}
			return '<p class="gx-zb-error">' . esc_html__( 'Booking options are temporarily unavailable. Please try again later.', 'gx-zoho-bookings' ) . '</p>';
		}

		if ( empty( $services ) || ! is_array( $services ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="gx-zb-info">' . esc_html__( 'No services found in your Zoho Bookings workspace.', 'gx-zoho-bookings' ) . '</p>';
			}
			return '';
		}

		// Determine fallback booking link.
		$fallback_url = $settings->get( 'embed_url' );

		// Stripe payment links + landing pages generated per service (see
		// GX_ZB_Service_Pages::generate_pages). Paid services with a payment
		// link get a Book & Pay button that goes straight to Stripe.
		$pages_map   = class_exists( 'GX_ZB_Service_Pages' ) ? GX_ZB_Service_Pages::instance()->get_map() : array();
		$stripe      = class_exists( 'GX_ZB_Stripe' ) ? GX_ZB_Stripe::instance() : null;
		$payments_on = $stripe && $stripe->is_enabled();
		$currency    = $payments_on ? strtoupper( $stripe->currency() ) : '';

		// Configurable button labels (block sidebar / shortcode atts).
		$book_label = sanitize_text_field( (string) ( $atts['book_label'] ?? '' ) );
		$pay_label  = sanitize_text_field( (string) ( $atts['pay_label'] ?? '' ) );
		if ( '' === $book_label ) {
			$book_label = __( 'Book Now', 'gx-zoho-bookings' );
		}
		if ( '' === $pay_label ) {
			$pay_label = __( 'Book & Pay', 'gx-zoho-bookings' );
		}

		$output = sprintf( '<div class="gx-zb-services gx-zb-cols-%d">', $columns );

		foreach ( $services as $service ) {
			$sid         = isset( $service['id'] ) ? (string) $service['id'] : '';
			$name        = isset( $service['name'] ) ? $service['name'] : '';
			$description = isset( $service['description'] ) ? $service['description'] : '';
			$duration    = isset( $service['duration'] ) ? $service['duration'] : '';
			$booking_url = isset( $service['booking_url'] ) ? $service['booking_url'] : '';

			// Zoho returns the numeric price as 'cost'; keep 'price' as fallback.
			$cost = 0.0;
			if ( isset( $service['cost'] ) && is_numeric( $service['cost'] ) ) {
				$cost = (float) $service['cost'];
			} elseif ( isset( $service['price'] ) && is_numeric( $service['price'] ) ) {
				$cost = (float) $service['price'];
			}

			$entry       = ( '' !== $sid && isset( $pages_map[ $sid ] ) ) ? $pages_map[ $sid ] : array();
			$payment_url = isset( $entry['payment_url'] ) ? $entry['payment_url'] : '';
			$page_url    = isset( $entry['page_url'] ) ? $entry['page_url'] : '';

			$output .= '<article class="gx-zb-service-card">';

			// Title links to the service landing page when one exists.
			if ( ! empty( $page_url ) ) {
				$output .= '<h3><a href="' . esc_url( $page_url ) . '">' . esc_html( $name ) . '</a></h3>';
			} else {
				$output .= '<h3>' . esc_html( $name ) . '</h3>';
			}

			if ( $show_duration && ! empty( $duration ) ) {
				$output .= '<p class="gx-zb-service-duration">' . esc_html( $duration ) . '</p>';
			}

			if ( $show_description && ! empty( $description ) ) {
				$output .= '<div class="gx-zb-service-description">' . wp_kses_post( $description ) . '</div>';
			}

			if ( $cost > 0 ) {
				$price_text = trim( $currency . ' ' . number_format_i18n( $cost, 2 ) );
				$output    .= '<p class="gx-zb-service-price">' . esc_html( $price_text ) . '</p>';
			} else {
				$output .= '<p class="gx-zb-service-price gx-zb-service-free">' . esc_html__( 'Free', 'gx-zoho-bookings' ) . '</p>';
			}

			if ( $payments_on && $cost > 0 && ( ! empty( $page_url ) || ! empty( $payment_url ) ) ) {
				// Paid service: prefer the landing page so the visitor picks a
				// time first and pays in Stripe Checkout (booking is created
				// after payment). Raw payment link only as a fallback when no
				// landing page exists.
				$pay_href = ! empty( $page_url ) ? $page_url : $payment_url;
				$output  .= sprintf(
					'<a href="%s" class="gx-zb-service-book gx-zb-service-pay" rel="nofollow">%s</a>',
					esc_url( $pay_href ),
					esc_html( $pay_label )
				);
			} else {
				$link_url = '';
				if ( ! empty( $page_url ) ) {
					$link_url = $page_url;
				} elseif ( ! empty( $booking_url ) ) {
					$link_url = $booking_url;
				} elseif ( ! empty( $fallback_url ) ) {
					$link_url = $fallback_url;
				}
				if ( ! empty( $link_url ) ) {
					$external = ( $link_url !== $page_url );
					$output  .= sprintf(
						'<a href="%s" class="gx-zb-service-book"%s>%s</a>',
						esc_url( $link_url ),
						$external ? ' target="_blank" rel="noopener noreferrer"' : '',
						esc_html( $book_label )
					);
				}
			}

			$output .= '</article>';
		}

		$output .= '</div>';

		// FUTURE (paid plan): allow staff selection, show available slots.

		return $output;
	}
}
