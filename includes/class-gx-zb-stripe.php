<?php
/**
 * Stripe integration class.
 *
 * @package GX_ZB_Stripe
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'GX_ZB_STRIPE_API' ) ) {
	define( 'GX_ZB_STRIPE_API', 'https://api.stripe.com' );
}

/**
 * Handles Stripe Checkout Sessions and payment retrieval.
 *
 * The class uses the Stripe REST API via wp_remote_post/get.
 * Payments are token-based; no card data touches the site.
 *
 * @since 1.4.0
 */
final class GX_ZB_Stripe {

	/**
	 * Singleton instance.
	 *
	 * @since 1.4.0
	 * @var GX_ZB_Stripe
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since 1.4.0
	 * @return GX_ZB_Stripe
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 1.4.0
	 */
	private function __construct() {}

	/**
	 * Checks whether Stripe payments are enabled and configured.
	 *
	 * @since 1.4.0
	 * @return bool
	 */
	public function is_enabled() {
		$options = get_option( 'gx_zb_settings', array() );
		return ! empty( $options['payments_enabled'] ) && ! empty( $options['stripe_sk'] );
	}

	/**
	 * Returns the Stripe publishable key.
	 *
	 * @since 1.4.0
	 * @return string
	 */
	public function publishable_key() {
		$options = get_option( 'gx_zb_settings', array() );
		return isset( $options['stripe_pk'] ) ? sanitize_text_field( $options['stripe_pk'] ) : '';
	}

	/**
	 * Returns the configured currency code (lowercase, 3-letter).
	 *
	 * @since 1.4.0
	 * @return string
	 */
	public function currency() {
		$options = get_option( 'gx_zb_settings', array() );
		$currency = isset( $options['stripe_currency'] ) ? strtolower( sanitize_text_field( $options['stripe_currency'] ) ) : '';
		if ( ! preg_match( '/^[a-z]{3}$/', $currency ) ) {
			$currency = 'usd';
		}
		return $currency;
	}

	/**
	 * Creates a Stripe Checkout Session.
	 *
	 * @since 1.4.0
	 *
	 * @param array $args {
	 *     Arguments for the checkout session.
	 *
	 *     @type int    $amount_cents    Amount in smallest currency unit (cents).
	 *     @type string $currency        Currency code (optional, falls back to configured).
	 *     @type string $product_name    Name of the product/service.
	 *     @type string $customer_email  Customer email address (optional).
	 *     @type string $success_url     URL to redirect after successful payment.
	 *     @type string $cancel_url      URL to redirect on cancellation.
	 *     @type array  $metadata        Associative array of string metadata.
	 * }
	 * @return array|WP_Error {
	 *     On success: array('id' => ..., 'url' => ...).
	 *     On error: WP_Error with code gx_zb_stripe_not_configured, gx_zb_stripe_http_error, or gx_zb_stripe_api_error.
	 * }
	 */
	public function create_checkout_session( $args ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'gx_zb_stripe_not_configured', __( 'Stripe is not configured or disabled.', 'gx-zoho-bookings' ) );
		}

		$amount_cents = isset( $args['amount_cents'] ) ? intval( $args['amount_cents'] ) : 0;
		if ( $amount_cents <= 0 ) {
			return new WP_Error( 'gx_zb_stripe_invalid_amount', __( 'Amount must be greater than 0.', 'gx-zoho-bookings' ) );
		}

		$currency        = ! empty( $args['currency'] ) ? strtolower( $args['currency'] ) : $this->currency();
		$product_name    = ! empty( $args['product_name'] ) ? $args['product_name'] : '';
		$customer_email  = ! empty( $args['customer_email'] ) ? $args['customer_email'] : '';
		// esc_url_raw strips the braces from Stripe's {CHECKOUT_SESSION_ID}
		// template placeholder, so shield it through the escape — without it
		// Stripe never substitutes the session id and the return handler
		// can't confirm payment.
		$success_url = '';
		if ( ! empty( $args['success_url'] ) ) {
			$success_url = str_replace(
				'GX-ZB-SESSION-PLACEHOLDER',
				'{CHECKOUT_SESSION_ID}',
				esc_url_raw( str_replace( '{CHECKOUT_SESSION_ID}', 'GX-ZB-SESSION-PLACEHOLDER', $args['success_url'] ) )
			);
		}
		$cancel_url      = ! empty( $args['cancel_url'] ) ? esc_url_raw( $args['cancel_url'] ) : '';
		$metadata        = ! empty( $args['metadata'] ) && is_array( $args['metadata'] ) ? $args['metadata'] : array();

		$options = get_option( 'gx_zb_settings', array() );
		$sk      = $options['stripe_sk'];

		$body = array(
			'mode'                      => 'payment',
			'success_url'               => $success_url,
			'cancel_url'                => $cancel_url,
			'line_items[0][quantity]'   => 1,
			'line_items[0][price_data][currency]'      => $currency,
			'line_items[0][price_data][unit_amount]'    => $amount_cents,
			'line_items[0][price_data][product_data][name]' => $product_name,
		);

		if ( ! empty( $customer_email ) ) {
			$body['customer_email'] = $customer_email;
		}

		foreach ( $metadata as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$body[ 'metadata[' . $key . ']' ] = (string) $value;
			}
		}

		$url      = GX_ZB_STRIPE_API . '/v1/checkout/sessions';
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 15,
				'headers'     => array(
					'Authorization'  => 'Bearer ' . $sk,
					'Content-Type'   => 'application/x-www-form-urlencoded',
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'gx_zb_stripe_http_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = __( 'Unknown Stripe error.', 'gx-zoho-bookings' );
			if ( isset( $data['error']['message'] ) ) {
				$message = $data['error']['message'];
			}
			return new WP_Error( 'gx_zb_stripe_api_error', $message );
		}

		if ( empty( $data['id'] ) || empty( $data['url'] ) ) {
			return new WP_Error( 'gx_zb_stripe_api_error', __( 'Invalid response from Stripe.', 'gx-zoho-bookings' ) );
		}

		return array(
			'id'  => $data['id'],
			'url' => $data['url'],
		);
	}

	/**
	 * Retrieves a Stripe Checkout Session.
	 *
	 * @since 1.4.0
	 *
	 * @param string $session_id Stripe Checkout Session ID.
	 * @return array|WP_Error {
	 *      On success: array(
	 *          'payment_status'  => string,
	 *          'amount_total'    => int,
	 *          'currency'        => string,
	 *          'customer_email'  => string,
	 *          'metadata'        => array,
	 *      )
	 *      On error: WP_Error.
	 * }
	 */
	public function retrieve_session( $session_id ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'gx_zb_stripe_not_configured', __( 'Stripe is not configured.', 'gx-zoho-bookings' ) );
		}

		$options = get_option( 'gx_zb_settings', array() );
		$sk      = $options['stripe_sk'];

		$url      = GX_ZB_STRIPE_API . '/v1/checkout/sessions/' . urlencode( $session_id );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $sk,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'gx_zb_stripe_http_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = __( 'Unknown Stripe error.', 'gx-zoho-bookings' );
			if ( isset( $data['error']['message'] ) ) {
				$message = $data['error']['message'];
			}
			return new WP_Error( 'gx_zb_stripe_api_error', $message );
		}

		return array(
			'payment_status' => isset( $data['payment_status'] ) ? $data['payment_status'] : '',
			'amount_total'   => isset( $data['amount_total'] ) ? intval( $data['amount_total'] ) : 0,
			'currency'       => isset( $data['currency'] ) ? $data['currency'] : '',
			'customer_email' => isset( $data['customer_email'] ) ? $data['customer_email'] : '',
			'metadata'       => isset( $data['metadata'] ) ? $data['metadata'] : array(),
		);
	}
	/**
	 * Create a Stripe Payment Link for a given product.
	 *
	 * Chains three Stripe API calls (Product -> Price -> Payment Link) using
	 * the same wp_remote_post, Bearer authentication and form-encoded body
	 * style as the existing create_checkout_session method.
	 *
	 * @since 1.5.0
	 *
	 * @param array $args {
	 *     Required arguments.
	 *
	 *     @type int    $amount_cents  Amount in cents (positive integer).
	 *     @type string $currency      Three-letter ISO currency code (e.g., 'usd').
	 *     @type string $product_name  Name of the product.
	 * }
	 * @return array{id: string, url: string} | \WP_Error  Associative array with
	 *                'id' (payment link ID) and 'url' on success, or WP_Error on failure.
	 *                Error codes: 'gx_zb_stripe_not_configured',
	 *                'gx_zb_stripe_http_error', 'gx_zb_stripe_api_error'.
	 */
	public function create_payment_link( array $args ) {
		if ( ! $this->is_enabled() ) {
			return new \WP_Error(
				'gx_zb_stripe_not_configured',
				__( 'Stripe is not enabled or not configured.', 'gx-zoho-bookings' )
			);
		}

		$settings = get_option( 'gx_zb_settings' );
		$sk       = $settings['stripe_sk'] ?? '';

		// 1. Create product
		$product_response = wp_remote_post(
			'https://api.stripe.com/v1/products',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $sk,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( array( 'name' => $args['product_name'] ) ),
			)
		);

		if ( is_wp_error( $product_response ) ) {
			return new \WP_Error(
				'gx_zb_stripe_http_error',
				$product_response->get_error_message()
			);
		}

		$product_body = wp_remote_retrieve_body( $product_response );
		$product_code = wp_remote_retrieve_response_code( $product_response );
		if ( $product_code < 200 || $product_code >= 300 ) {
			$decoded = json_decode( $product_body, true );
			$message = isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: __( 'Stripe API error creating product.', 'gx-zoho-bookings' );

			return new \WP_Error( 'gx_zb_stripe_api_error', $message );
		}

		$product_data = json_decode( $product_body, true );
		$product_id   = $product_data['id'] ?? '';
		if ( empty( $product_id ) ) {
			return new \WP_Error(
				'gx_zb_stripe_api_error',
				__( 'Invalid product ID returned.', 'gx-zoho-bookings' )
			);
		}

		// 2. Create price for that product
		$price_response = wp_remote_post(
			'https://api.stripe.com/v1/prices',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $sk,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query(
					array(
						'product'     => $product_id,
						'unit_amount' => $args['amount_cents'],
						'currency'    => $args['currency'],
					)
				),
			)
		);

		if ( is_wp_error( $price_response ) ) {
			return new \WP_Error(
				'gx_zb_stripe_http_error',
				$price_response->get_error_message()
			);
		}

		$price_body = wp_remote_retrieve_body( $price_response );
		$price_code = wp_remote_retrieve_response_code( $price_response );
		if ( $price_code < 200 || $price_code >= 300 ) {
			$decoded = json_decode( $price_body, true );
			$message = isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: __( 'Stripe API error creating price.', 'gx-zoho-bookings' );

			return new \WP_Error( 'gx_zb_stripe_api_error', $message );
		}

		$price_data = json_decode( $price_body, true );
		$price_id   = $price_data['id'] ?? '';
		if ( empty( $price_id ) ) {
			return new \WP_Error(
				'gx_zb_stripe_api_error',
				__( 'Invalid price ID returned.', 'gx-zoho-bookings' )
			);
		}

		// 3. Create payment link for that price
		$payment_link_response = wp_remote_post(
			'https://api.stripe.com/v1/payment_links',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $sk,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query(
					array(
						'line_items' => array(
							array(
								'price'    => $price_id,
								'quantity' => 1,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $payment_link_response ) ) {
			return new \WP_Error(
				'gx_zb_stripe_http_error',
				$payment_link_response->get_error_message()
			);
		}

		$payment_link_body = wp_remote_retrieve_body( $payment_link_response );
		$payment_link_code = wp_remote_retrieve_response_code( $payment_link_response );
		if ( $payment_link_code < 200 || $payment_link_code >= 300 ) {
			$decoded = json_decode( $payment_link_body, true );
			$message = isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: __( 'Stripe API error creating payment link.', 'gx-zoho-bookings' );

			return new \WP_Error( 'gx_zb_stripe_api_error', $message );
		}

		$payment_link_data = json_decode( $payment_link_body, true );

		return array(
			'id'  => $payment_link_data['id'] ?? '',
			'url' => $payment_link_data['url'] ?? '',
		);
	}
}
// FUTURE: subscriptions, deposits, refunds will extend this class or be added here.