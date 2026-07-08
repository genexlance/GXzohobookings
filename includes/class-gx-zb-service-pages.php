<?php
defined( 'ABSPATH' ) || exit;

/**
 * Service landing pages with Stripe payment links.
 *
 * @package GX_Zoho_Bookings
 * @since 1.5.0
 */
final class GX_ZB_Service_Pages {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Private constructor to prevent direct creation.
	 */
	private function __construct() {}

	/**
	 * Returns the single instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Prevents cloning.
	 */
	private function __clone() {}

	/**
	 * Prevents unserializing.
	 */
	public function __wakeup() {}

	/**
	 * Registers shortcodes and admin handlers.
	 */
	public function register(): void {
		add_shortcode( 'zoho_bookings_service', array( $this, 'shortcode' ) );
		add_action( 'admin_post_gx_zb_gen_pages', array( $this, 'handle_generate' ) );
	}

	/**
	 * Renders a single service landing card.
	 *
	 * @param array $atts Shortcode attributes (id).
	 * @return string
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id' => '',
			),
			$atts
		);

		$sid = (string) $atts['id'];
		if ( '' === $sid ) {
			return '';
		}

		if ( ! wp_style_is( 'gx-zb-frontend', 'enqueued' ) ) {
			wp_enqueue_style( 'gx-zb-frontend', GX_ZB_URL . 'assets/css/gx-zb-frontend.css', array(), GX_ZB_VERSION );
		}

		$services = $this->get_services();
		if ( empty( $services ) ) {
			return '';
		}

		$service = null;
		foreach ( $services as $svc ) {
			if ( isset( $svc['id'] ) && (string) $svc['id'] === $sid ) {
				$service = $svc;
				break;
			}
		}

		if ( null === $service ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="gx-zb-error"><strong>' . esc_html__( 'Service not found', 'gx-zoho-bookings' ) . '</strong></p>';
			}
			return '';
		}

		$name        = isset( $service['name'] ) ? $service['name'] : '';
		$duration    = isset( $service['duration'] ) ? $service['duration'] : '';
		$cost        = isset( $service['cost'] ) ? floatval( $service['cost'] ) : 0.0;
		$description = isset( $service['description'] ) ? $service['description'] : '';

		$paid       = $cost > 0;
		$currency   = '';
		if ( $paid ) {
			$currency = $this->get_stripe_currency();
		}
		$price_text = '';
		if ( $paid && $currency ) {
			$price_text = esc_html( strtoupper( $currency ) . ' ' . number_format_i18n( $cost, 2 ) );
		} elseif ( ! $paid ) {
			$price_text = esc_html__( 'Free', 'gx-zoho-bookings' );
		} elseif ( $paid && ! $currency ) {
			$price_text = esc_html( number_format_i18n( $cost, 2 ) );
		}

		// Time-first flow for every service: the visitor picks staff, date and
		// slot in the native form. Paid services then go through Stripe Checkout
		// and the appointment is only booked once payment is confirmed —
		// standalone payment links skipped booking entirely, so they are no
		// longer used on landing pages.
		$button_html = do_shortcode( '[zoho_bookings_book service="' . esc_attr( $sid ) . '"]' );

		$output  = '<div class="gx-zb-service-landing">';
		$output .= '<h2>' . esc_html( $name ) . '</h2>';

		if ( '' !== (string) $duration ) {
			/* translators: %s: duration in minutes */
			$output .= '<p class="gx-zb-sl-meta">' . esc_html( sprintf( __( '%s minutes', 'gx-zoho-bookings' ), $duration ) ) . '</p>';
		}

		if ( '' !== $price_text ) {
			$cls     = $paid ? 'gx-zb-sl-price' : 'gx-zb-sl-price gx-zb-sl-free';
			$output .= '<p class="' . esc_attr( $cls ) . '">' . $price_text . '</p>';
		}

		if ( '' !== $description ) {
			$output .= '<div class="gx-zb-sl-desc">' . wp_kses_post( $description ) . '</div>';
		}

		$output .= '<p class="gx-zb-sl-action">' . $button_html . '</p>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Generates or refreshes a WordPress page per service, creating Stripe payment links where applicable.
	 *
	 * @return array[] Summary: { name, page_url, payment_url, created }.
	 */
	public function generate_pages(): array {
		$services = $this->get_services();
		if ( empty( $services ) ) {
			return array();
		}

		$map     = get_option( 'gx_zb_service_pages', array() );
		$summary = array();

		foreach ( $services as $service ) {
			$sid  = isset( $service['id'] ) ? (string) $service['id'] : '';
			$name = isset( $service['name'] ) ? $service['name'] : '';
			$cost = isset( $service['cost'] ) ? floatval( $service['cost'] ) : 0.0;

			$payment_url = '';
			if ( $cost > 0 && class_exists( 'GX_ZB_Stripe' ) && GX_ZB_Stripe::instance()->is_enabled() ) {
				$stripe = GX_ZB_Stripe::instance();
				$result = $stripe->create_payment_link(
					array(
						'amount_cents' => (int) round( $cost * 100 ),
						'currency'     => $stripe->currency(),
						'product_name' => $name,
					)
				);
				if ( ! is_wp_error( $result ) && isset( $result['url'] ) ) {
					$payment_url = $result['url'];
				}
			}

			$existing = isset( $map[ $sid ] ) ? $map[ $sid ] : null;
			$page_id  = null;
			$created  = false;

			// If the page already exists, reuse it and DO NOT overwrite its
			// content — an editor may have customised it in the block editor.
			if ( $existing && isset( $existing['page_id'] ) && $existing['page_id'] ) {
				$page = get_post( $existing['page_id'] );
				if ( $page && 'page' === $page->post_type && 'publish' === $page->post_status ) {
					$page_id = $page->ID;
				}
			}

			if ( ! $page_id ) {
				$page_data = array(
					'post_title'   => $name,
					'post_content' => $this->build_block_content( $sid, $name ),
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_name'    => 'book-' . sanitize_title( $sid ),
				);
				$page_id   = wp_insert_post( $page_data, true );
				if ( is_wp_error( $page_id ) ) {
					continue;
				}
				$created = true;
			}

			$map[ $sid ] = array(
				'page_id'     => $page_id,
				'payment_url' => $payment_url,
				'name'        => $name,
				'cost'        => $cost,
				'page_url'    => get_permalink( $page_id ),
			);

			$summary[] = array(
				'name'        => $name,
				'page_url'    => get_permalink( $page_id ),
				'payment_url' => $payment_url,
				'created'     => $created,
			);
		}

		update_option( 'gx_zb_service_pages', $map, 'no' );

		return $summary;
	}

	/**
	 * Build the initial Gutenberg block markup for a service landing page.
	 *
	 * The page opens as real, editable blocks — a heading, an intro paragraph,
	 * and the dynamic gx-zoho-bookings/service block — so editors can rearrange
	 * or add blocks around the service card in the block editor.
	 *
	 * @param string $sid  Service id.
	 * @param string $name Service name.
	 * @return string Block markup.
	 */
	private function build_block_content( $sid, $name ): string {
		$service_block = '<!-- wp:gx-zoho-bookings/service ' . wp_json_encode( array( 'serviceId' => (string) $sid ) ) . ' /-->';

		return implode(
			"\n\n",
			array(
				'<!-- wp:heading {"level":1} --><h1>' . esc_html( $name ) . '</h1><!-- /wp:heading -->',
				'<!-- wp:paragraph --><p>' . esc_html__( 'Book your session below.', 'gx-zoho-bookings' ) . '</p><!-- /wp:paragraph -->',
				$service_block,
			)
		);
	}

	/**
	 * Returns the full service-page map from the option.
	 *
	 * @return array Map of sid => { page_id, payment_url, name, cost, page_url }.
	 */
	public function get_map(): array {
		return get_option( 'gx_zb_service_pages', array() );
	}

	/**
	 * Admin-post handler for page generation.
	 */
	public function handle_generate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gx-zoho-bookings' ) );
		}

		check_admin_referer( 'gx_zb_gen_pages' );

		$this->generate_pages();

		$redirect = wp_get_referer();
		if ( ! $redirect || false === strpos( $redirect, admin_url() ) ) {
			$redirect = admin_url( 'admin.php?page=gx-zoho-bookings' );
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'gx_zb_gen_pages' => 'done' ),
				$redirect
			)
		);
		exit;
	}

	/**
	 * Fetches services from the Zoho Bookings API.
	 *
	 * @return array
	 */
	private function get_services(): array {
		if ( ! class_exists( 'GX_ZB_API_Client' ) ) {
			return array();
		}
		$api = GX_ZB_API_Client::instance();
		if ( ! method_exists( $api, 'get_services' ) ) {
			return array();
		}
		$services = $api->get_services();
		return is_array( $services ) ? $services : array();
	}

	/**
	 * Retrieves the Stripe currency.
	 *
	 * @return string
	 */
	private function get_stripe_currency(): string {
		if ( class_exists( 'GX_ZB_Stripe' ) ) {
			return GX_ZB_Stripe::instance()->currency();
		}
		return '';
	}
}