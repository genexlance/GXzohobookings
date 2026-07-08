<?php
defined( 'ABSPATH' ) || exit;

/**
 * Zoho Bookings OAuth 2.0 handler.
 *
 * Manages the authorization code flow, token storage, refresh,
 * and revoke operations for the Zoho Bookings API.
 *
 * @package GX_Zoho_Bookings
 * @since   1.0.0
 */
final class GX_ZB_OAuth {

	/**
	 * The single instance of the class.
	 *
	 * @since 1.0.0
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since 1.0.0
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent external instantiation.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	/**
	 * Registers WordPress hooks required by the class.
	 *
	 * Should be called during plugin bootstrap.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init_hooks() {
		add_action( 'admin_init', array( $this, 'handle_callback' ) );
	}

	/**
	 * Builds the authorization URL for the Zoho OAuth consent screen.
	 *
	 * @since 1.0.0
	 * @return string Full URL, or empty string if client_id is missing.
	 */
	public function authorize_url() {
		$settings   = GX_ZB_Settings::instance();
		$client_id  = $settings->get( 'client_id' );

		if ( empty( $client_id ) ) {
			return '';
		}

		$region       = $settings->get( 'region', 'us' );
		$accounts_url = GX_ZB_Regions::accounts_base( $region );
		$redirect_uri = $this->redirect_uri();
		$state        = wp_create_nonce( 'gx_zb_oauth_state' );

		// FUTURE (paid plan): append extra scopes here (e.g. payments, Zoho CRM
		// sync) once those integrations ship.
		$params = array(
			'scope'         => 'zohobookings.data.CREATE,zohobookings.data.READ',
			'client_id'     => $client_id,
			'response_type' => 'code',
			'redirect_uri'  => $redirect_uri,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		);

		return trailingslashit( $accounts_url ) . 'oauth/v2/auth?' . http_build_query( $params );
	}

	/**
	 * Returns the redirect URI used for the OAuth flow.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function redirect_uri() {
		return admin_url( 'options-general.php?page=gx-zoho-bookings&gx_zb_oauth=callback' );
	}

	/**
	 * Handles the OAuth callback on admin_init.
	 *
	 * Verifies the state nonce, exchanges the authorization code for tokens,
	 * and redirects the user back to the settings page with the appropriate
	 * notice flag.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_callback() {
		if ( ! isset( $_GET['gx_zb_oauth'] ) || 'callback' !== $_GET['gx_zb_oauth'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gx-zoho-bookings' ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! wp_verify_nonce( $state, 'gx_zb_oauth_state' ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=gx-zoho-bookings&gx_zb_error=bad_state' ) );
			exit;
		}

		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		if ( ! empty( $error ) ) {
			// Zoho denied access or another error occurred.
			wp_safe_redirect( admin_url( 'options-general.php?page=gx-zoho-bookings&gx_zb_error=oauth_denied' ) );
			exit;
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( empty( $code ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=gx-zoho-bookings&gx_zb_error=token_exchange_failed' ) );
			exit;
		}

		$settings    = GX_ZB_Settings::instance();
		$client_id   = $settings->get( 'client_id' );
		$secret      = $settings->get( 'client_secret' );
		$region      = $settings->get( 'region', 'us' );
		$accounts_url = GX_ZB_Regions::accounts_base( $region );

		$token_url = trailingslashit( $accounts_url ) . 'oauth/v2/token';

		$response = wp_remote_post(
			$token_url,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => $client_id,
					'client_secret' => $secret,
					'redirect_uri'  => $this->redirect_uri(),
					'code'          => $code,
				),
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=gx-zoho-bookings&gx_zb_error=token_exchange_failed' ) );
			exit;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data['access_token'] ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=gx-zoho-bookings&gx_zb_error=token_exchange_failed' ) );
			exit;
		}

		$this->store_tokens( $data );

		wp_safe_redirect( admin_url( 'options-general.php?page=gx-zoho-bookings&gx_zb_connected=1' ) );
		exit;
	}

	/**
	 * Retrieves a valid access token, refreshing it if necessary.
	 *
	 * @since 1.0.0
	 * @return string|WP_Error Access token on success, WP_Error on failure.
	 */
	public function get_access_token() {
		$tokens = get_option( GX_ZB_OPTION_TOKENS, array() );

		if ( empty( $tokens['refresh_token'] ) ) {
			return new WP_Error( 'gx_zb_not_connected', __( 'Not connected to Zoho.', 'gx-zoho-bookings' ) );
		}

		$expires_at = isset( $tokens['expires_at'] ) ? (int) $tokens['expires_at'] : 0;

		// Refresh if token is expired or will expire in 60 seconds.
		if ( $expires_at - 60 < time() ) {
			$result = $this->refresh();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			// Reload tokens after successful refresh.
			$tokens = get_option( GX_ZB_OPTION_TOKENS, array() );
		}

		if ( empty( $tokens['access_token'] ) ) {
			return new WP_Error( 'gx_zb_not_connected', __( 'Access token missing.', 'gx-zoho-bookings' ) );
		}

		return $tokens['access_token'];
	}

	/**
	 * Refreshes the access token using the stored refresh token.
	 *
	 * @since 1.0.0
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function refresh() {
		$tokens = get_option( GX_ZB_OPTION_TOKENS, array() );

		if ( empty( $tokens['refresh_token'] ) ) {
			return new WP_Error( 'gx_zb_not_connected', __( 'No refresh token available.', 'gx-zoho-bookings' ) );
		}

		$settings    = GX_ZB_Settings::instance();
		$client_id   = $settings->get( 'client_id' );
		$secret      = $settings->get( 'client_secret' );
		$region      = $settings->get( 'region', 'us' );
		$accounts_url = GX_ZB_Regions::accounts_base( $region );
		$token_url   = trailingslashit( $accounts_url ) . 'oauth/v2/token';

		$response = wp_remote_post(
			$token_url,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $client_id,
					'client_secret' => $secret,
					'refresh_token' => $tokens['refresh_token'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'gx_zb_http_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code || empty( $data['access_token'] ) ) {
			// Check if the refresh token was invalid / revoked.
			$error = isset( $data['error'] ) ? $data['error'] : '';
			if ( 'invalid_grant' === $error || 'invalid_token' === $error ) {
				// Wipe stored tokens and flag it so the admin sees a "reconnect" notice.
				delete_option( GX_ZB_OPTION_TOKENS );
				update_option( 'gx_zb_needs_reconnect', 1, false );
				return new WP_Error( 'gx_zb_auth_expired', __( 'Zoho token is invalid or has been revoked. Please reconnect.', 'gx-zoho-bookings' ) );
			}

			return new WP_Error( 'gx_zb_api_error', __( 'Token refresh failed.', 'gx-zoho-bookings' ) );
		}

		// Store updated tokens.
		$this->store_tokens( $data );
		return true;
	}

	/**
	 * Checks whether a valid refresh token is stored (i.e., connected to Zoho).
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_connected() {
		$tokens = get_option( GX_ZB_OPTION_TOKENS, array() );
		return ! empty( $tokens['refresh_token'] );
	}

	/**
	 * Revokes the current refresh token and removes all stored token data
	 * and cached API responses.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function disconnect() {
		$tokens = get_option( GX_ZB_OPTION_TOKENS, array() );

		if ( ! empty( $tokens['refresh_token'] ) ) {
			$settings    = GX_ZB_Settings::instance();
			$region      = $settings->get( 'region', 'us' );
			$accounts_url = GX_ZB_Regions::accounts_base( $region );
			$revoke_url   = trailingslashit( $accounts_url ) . 'oauth/v2/token/revoke';

			// Attempt to revoke – ignore any error, as the token may already be invalid.
			wp_remote_post(
				$revoke_url,
				array(
					'timeout' => 15,
					'body'    => array( 'token' => $tokens['refresh_token'] ),
				)
			);
		}

		// Delete the stored tokens.
		delete_option( GX_ZB_OPTION_TOKENS );

		// Flush cached API data.
		$transient_keys = get_option( 'gx_zb_transient_keys', array() );
		if ( is_array( $transient_keys ) ) {
			foreach ( $transient_keys as $key ) {
				delete_transient( $key );
			}
		}
		delete_option( 'gx_zb_transient_keys' );
	}

	/**
	 * Stores token data received from Zoho OAuth endpoints.
	 *
	 * @since 1.0.0
	 * @param array $token_response Raw response array from Zoho.
	 * @return void
	 */
	public function store_tokens( array $token_response ) {
		$existing   = get_option( GX_ZB_OPTION_TOKENS, array() );
		$existing   = is_array( $existing ) ? $existing : array();
		$expires_in = isset( $token_response['expires_in'] ) ? (int) $token_response['expires_in'] : 3600;

		// Zoho refresh responses omit refresh_token and api_domain — keep the
		// stored values in that case or the connection would silently break.
		$refresh_token = isset( $token_response['refresh_token'] )
			? sanitize_text_field( $token_response['refresh_token'] )
			: ( isset( $existing['refresh_token'] ) ? $existing['refresh_token'] : '' );
		$api_domain    = isset( $token_response['api_domain'] )
			? esc_url_raw( untrailingslashit( $token_response['api_domain'] ) )
			: ( isset( $existing['api_domain'] ) ? $existing['api_domain'] : '' );

		$tokens = array(
			'access_token'  => isset( $token_response['access_token'] ) ? sanitize_text_field( $token_response['access_token'] ) : '',
			'refresh_token' => $refresh_token,
			'expires_at'    => time() + $expires_in,
			'api_domain'    => $api_domain,
			'connected_at'  => isset( $existing['connected_at'] ) ? (int) $existing['connected_at'] : time(),
		);

		// Store without autoload (rarely needed) and clear any reconnect flag.
		update_option( GX_ZB_OPTION_TOKENS, $tokens, false );
		delete_option( 'gx_zb_needs_reconnect' );
	}
}