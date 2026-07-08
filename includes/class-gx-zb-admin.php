<?php
/**
 * Admin settings screen, connection status panel and admin notices.
 *
 * @package GX_Zoho_Bookings
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings > Zoho Bookings screen.
 */
final class GX_ZB_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var GX_ZB_Admin|null
	 */
	private static $instance = null;

	public static function instance(): GX_ZB_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Private constructor for singleton.
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_gx_zb_flush_cache', array( $this, 'handle_flush_cache' ) );
		add_action( 'admin_post_gx_zb_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_gx_zb_mcp_regen_key', array( $this, 'handle_mcp_regen_key' ) );
	}

	public function add_menu(): void {
		add_options_page(
			esc_html__( 'Zoho Bookings', 'gx-zoho-bookings' ),
			esc_html__( 'Zoho Bookings', 'gx-zoho-bookings' ),
			'manage_options',
			'gx-zoho-bookings',
			array( $this, 'render_page' )
		);
	}

	public function admin_init(): void {
		register_setting(
			'gx_zb_settings_group',
			GX_ZB_OPTION_SETTINGS,
			array( GX_ZB_Settings::instance(), 'sanitize' )
		);

		add_settings_section(
			'gx_zb_main_section',
			esc_html__( 'General Settings', 'gx-zoho-bookings' ),
			'__return_empty_string',
			'gx-zoho-bookings'
		);

		add_settings_field(
			'gx_zb_mode',
			esc_html__( 'Mode', 'gx-zoho-bookings' ),
			array( $this, 'field_mode' ),
			'gx-zoho-bookings',
			'gx_zb_main_section'
		);

		add_settings_field(
			'gx_zb_region',
			esc_html__( 'Data Center Region', 'gx-zoho-bookings' ),
			array( $this, 'field_region' ),
			'gx-zoho-bookings',
			'gx_zb_main_section'
		);

		add_settings_field(
			'gx_zb_embed_url',
			esc_html__( 'Booking Page URL', 'gx-zoho-bookings' ),
			array( $this, 'field_embed_url' ),
			'gx-zoho-bookings',
			'gx_zb_main_section'
		);

		add_settings_field(
			'gx_zb_client_id',
			esc_html__( 'Client ID', 'gx-zoho-bookings' ),
			array( $this, 'field_client_id' ),
			'gx-zoho-bookings',
			'gx_zb_main_section'
		);

		add_settings_field(
			'gx_zb_client_secret',
			esc_html__( 'Client Secret', 'gx-zoho-bookings' ),
			array( $this, 'field_client_secret' ),
			'gx-zoho-bookings',
			'gx_zb_main_section'
		);

		add_settings_field(
			'gx_zb_cache_minutes',
			esc_html__( 'Cache Duration (minutes)', 'gx-zoho-bookings' ),
			array( $this, 'field_cache_minutes' ),
			'gx-zoho-bookings',
			'gx_zb_main_section'
		);

		add_settings_field(
			'gx_zb_redirect_uri',
			esc_html__( 'Redirect URI for Zoho API Console', 'gx-zoho-bookings' ),
			array( $this, 'field_redirect_uri' ),
			'gx-zoho-bookings',
			'gx_zb_main_section'
		);

		add_settings_section(
			'gx_zb_mcp_section',
			esc_html__( 'AI Agent Access (MCP)', 'gx-zoho-bookings' ),
			array( $this, 'mcp_section_intro' ),
			'gx-zoho-bookings'
		);

		add_settings_field(
			'gx_zb_mcp_enabled',
			esc_html__( 'Enable MCP endpoint', 'gx-zoho-bookings' ),
			array( $this, 'field_mcp_enabled' ),
			'gx-zoho-bookings',
			'gx_zb_mcp_section'
		);

		add_settings_field(
			'gx_zb_mcp_endpoint',
			esc_html__( 'MCP endpoint URL', 'gx-zoho-bookings' ),
			array( $this, 'field_mcp_endpoint' ),
			'gx-zoho-bookings',
			'gx_zb_mcp_section'
		);

		add_settings_field(
			'gx_zb_mcp_api_key',
			esc_html__( 'MCP API key', 'gx-zoho-bookings' ),
			array( $this, 'field_mcp_api_key' ),
			'gx-zoho-bookings',
			'gx_zb_mcp_section'
		);

		add_settings_section(
			'gx_zb_payments_section',
			esc_html__( 'Payments (Stripe)', 'gx-zoho-bookings' ),
			array( $this, 'payments_section_intro' ),
			'gx-zoho-bookings'
		);

		add_settings_field(
			'gx_zb_payments_enabled',
			esc_html__( 'Enable payments', 'gx-zoho-bookings' ),
			array( $this, 'field_payments_enabled' ),
			'gx-zoho-bookings',
			'gx_zb_payments_section'
		);

		add_settings_field(
			'gx_zb_stripe_pk',
			esc_html__( 'Stripe publishable key', 'gx-zoho-bookings' ),
			array( $this, 'field_stripe_pk' ),
			'gx-zoho-bookings',
			'gx_zb_payments_section'
		);

		add_settings_field(
			'gx_zb_stripe_sk',
			esc_html__( 'Stripe secret key', 'gx-zoho-bookings' ),
			array( $this, 'field_stripe_sk' ),
			'gx-zoho-bookings',
			'gx_zb_payments_section'
		);

		add_settings_field(
			'gx_zb_stripe_currency',
			esc_html__( 'Currency', 'gx-zoho-bookings' ),
			array( $this, 'field_stripe_currency' ),
			'gx-zoho-bookings',
			'gx_zb_payments_section'
		);

		add_settings_section(
			'gx_zb_services_block_section',
			esc_html__( 'Services block', 'gx-zoho-bookings' ),
			array( $this, 'services_block_section_intro' ),
			'gx-zoho-bookings'
		);

		add_settings_field(
			'gx_zb_services_css',
			esc_html__( 'Custom CSS', 'gx-zoho-bookings' ),
			array( $this, 'field_services_css' ),
			'gx-zoho-bookings',
			'gx_zb_services_block_section'
		);
	}

	/**
	 * Intro text for the Services block settings section.
	 */
	public function services_block_section_intro(): void {
		echo '<p>' . esc_html__( 'Style the services grid to match your theme. This CSS loads on any page that renders the services block or the [zoho_bookings_services] shortcode. Button text is set per block in the editor sidebar (or via the book_label / pay_label shortcode attributes).', 'gx-zoho-bookings' ) . '</p>';
	}

	/**
	 * Custom services-block CSS textarea.
	 */
	public function field_services_css(): void {
		$value = (string) GX_ZB_Settings::instance()->get( 'services_css' );
		?>
		<textarea name="<?php echo esc_attr( GX_ZB_OPTION_SETTINGS ); ?>[services_css]" rows="12" class="large-text code" placeholder=".gx-zb-service-card { border-radius: 16px; }"><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Useful selectors: .gx-zb-services, .gx-zb-service-card, .gx-zb-service-price, .gx-zb-service-free, .gx-zb-service-book, .gx-zb-service-pay, .gx-zb-service-duration, .gx-zb-service-description.', 'gx-zoho-bookings' ); ?></p>
		<?php
	}

	/**
	 * Intro text for the Payments settings section.
	 */
	public function payments_section_intro(): void {
		$configured = ( '' !== (string) GX_ZB_Settings::instance()->get( 'stripe_sk' ) );
		echo '<p>' . esc_html__( 'Collect payment for paid services via Stripe Checkout (customers pay on Stripe’s secure hosted page — no card details touch this site). Use test keys for a sandbox.', 'gx-zoho-bookings' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Stripe:', 'gx-zoho-bookings' ) . '</strong> ' . ( $configured ? esc_html__( 'configured', 'gx-zoho-bookings' ) : esc_html__( 'not configured', 'gx-zoho-bookings' ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Add the booking form to any page with the shortcode', 'gx-zoho-bookings' ) . ' <code>[zoho_bookings_book]</code>.</p>';
	}

	/**
	 * Payments enable checkbox.
	 */
	public function field_payments_enabled(): void {
		$enabled = (bool) GX_ZB_Settings::instance()->get( 'payments_enabled' );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( GX_ZB_OPTION_SETTINGS ); ?>[payments_enabled]" value="1" <?php checked( $enabled ); ?>>
			<?php esc_html_e( 'Require Stripe payment for services that have a price', 'gx-zoho-bookings' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Free services (price 0) are always booked without payment.', 'gx-zoho-bookings' ); ?></p>
		<?php
	}

	/**
	 * Stripe publishable key field.
	 */
	public function field_stripe_pk(): void {
		$value = (string) GX_ZB_Settings::instance()->get( 'stripe_pk' );
		?>
		<input type="text" name="<?php echo esc_attr( GX_ZB_OPTION_SETTINGS ); ?>[stripe_pk]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="pk_test_…">
		<?php
	}

	/**
	 * Stripe secret key field (masked; blank submit keeps the stored value).
	 */
	public function field_stripe_sk(): void {
		$secret = (string) GX_ZB_Settings::instance()->get( 'stripe_sk' );
		?>
		<input type="password" name="<?php echo esc_attr( GX_ZB_OPTION_SETTINGS ); ?>[stripe_sk]" value="" class="regular-text" placeholder="<?php echo '' !== $secret ? esc_attr( '••••••••' ) : 'sk_test_…'; ?>">
		<?php if ( '' !== $secret ) : ?>
			<p class="description"><?php esc_html_e( 'A secret key is saved. Leave blank to keep it unchanged.', 'gx-zoho-bookings' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Stripe currency field.
	 */
	public function field_stripe_currency(): void {
		$value = (string) GX_ZB_Settings::instance()->get( 'stripe_currency' );
		?>
		<input type="text" name="<?php echo esc_attr( GX_ZB_OPTION_SETTINGS ); ?>[stripe_currency]" value="<?php echo esc_attr( $value ); ?>" class="small-text" maxlength="3" style="text-transform:uppercase;">
		<p class="description"><?php esc_html_e( 'Three-letter ISO currency code, e.g. usd, cad, eur.', 'gx-zoho-bookings' ); ?></p>
		<?php
	}

	/**
	 * Intro text for the MCP settings section.
	 */
	public function mcp_section_intro(): void {
		echo '<p>' . esc_html__( 'Let an AI booking agent (Claude or any MCP client) list services, check availability and manage appointments through a secure endpoint. Requires API mode with an active Zoho connection.', 'gx-zoho-bookings' ) . '</p>';
	}

	/**
	 * MCP enable checkbox.
	 */
	public function field_mcp_enabled(): void {
		$enabled = (bool) GX_ZB_Settings::instance()->get( 'mcp_enabled' );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( GX_ZB_OPTION_SETTINGS ); ?>[mcp_enabled]" value="1" <?php checked( $enabled ); ?>>
			<?php esc_html_e( 'Allow authenticated MCP clients to connect', 'gx-zoho-bookings' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'The endpoint refuses all requests while disabled or while no API key exists.', 'gx-zoho-bookings' ); ?></p>
		<?php
	}

	/**
	 * Read-only MCP endpoint URL.
	 */
	public function field_mcp_endpoint(): void {
		?>
		<input type="text" readonly value="<?php echo esc_url( rest_url( 'gx-zb/v1/mcp' ) ); ?>" class="regular-text gx-zb-copy-field" onClick="this.select();">
		<p class="description"><?php esc_html_e( 'Give this URL to your MCP client, with the API key as a Bearer token. HTTPS strongly recommended.', 'gx-zoho-bookings' ); ?></p>
		<?php
	}

	/**
	 * Read-only MCP API key + regenerate button.
	 */
	public function field_mcp_api_key(): void {
		$key = (string) GX_ZB_Settings::instance()->get( 'mcp_api_key' );
		?>
		<input type="text" readonly value="<?php echo esc_attr( $key ); ?>" placeholder="<?php esc_attr_e( 'No key generated yet', 'gx-zoho-bookings' ); ?>" class="regular-text gx-zb-copy-field" onClick="this.select();">
		<p class="description"><?php esc_html_e( 'Anyone holding this key can read and manage bookings. Regenerate it to revoke existing agents.', 'gx-zoho-bookings' ); ?></p>
		<?php
		// Regenerate posts to admin-post.php via its own form, rendered after
		// the main settings form by render_page (nested forms are invalid HTML).
	}

	/**
	 * admin-post handler: (re)generate the MCP API key.
	 */
	public function handle_mcp_regen_key(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'gx-zoho-bookings' ) );
		}
		check_admin_referer( 'gx_zb_mcp_regen_key', 'gx_zb_mcp_regen_nonce' );

		$settings = GX_ZB_Settings::instance();
		$all      = $settings->all();

		$all['mcp_api_key'] = wp_generate_password( 40, false, false );
		update_option( GX_ZB_OPTION_SETTINGS, $settings->sanitize_with_key( $all ) );

		wp_safe_redirect( admin_url( 'options-general.php?page=gx-zoho-bookings&gx_zb_key_regen=1' ) );
		exit;
	}

	public function field_mode(): void {
		$settings = GX_ZB_Settings::instance()->all();
		$current  = isset( $settings['mode'] ) ? $settings['mode'] : 'embed';
		?>
		<label>
			<input type="radio" name="<?php echo esc_attr( GX_ZB_OPTION_SETTINGS ); ?>[mode]" value="embed" <?php checked( 'embed', $current ); ?>>
			<?php esc_html_e( 'Embed', 'gx-zoho-bookings' ); ?>
		</label>
		<br>
		<label>
			<input type="radio" name="<?php echo esc_attr( GX_ZB_OPTION_SETTINGS ); ?>[mode]" value="api" <?php checked( 'api', $current ); ?>>
			<?php esc_html_e( 'API (Read‑only display)', 'gx-zoho-bookings' ); ?>
		</label>
		<?php
	}

	public function field_region(): void {
		$settings = GX_ZB_Settings::instance()->all();
		$current  = isset( $settings['region'] ) ? $settings['region'] : 'us';
		$choices  = GX_ZB_Regions::choices();
		?>
		<select name="<?php echo esc_attr( GX_ZB_OPTION_SETTINGS ); ?>[region]">
			<?php foreach ( $choices as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $current ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function field_embed_url(): void {
		$settings = GX_ZB_Settings::instance()->all();
		$value    = isset( $settings['embed_url'] ) ? $settings['embed_url'] : '';
		?>
		<input type="url" name="<?php echo esc_attr( GX_ZB_OPTION_SETTINGS ); ?>[embed_url]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'https://workspace.zohobookings.com/...', 'gx-zoho-bookings' ); ?>">
		<p class="description"><?php esc_html_e( 'Paste your Zoho Bookings page or widget URL.', 'gx-zoho-bookings' ); ?></p>
		<?php
	}

	public function field_client_id(): void {
		$settings = GX_ZB_Settings::instance()->all();
		$value    = isset( $settings['client_id'] ) ? $settings['client_id'] : '';
		?>
		<input type="text" name="<?php echo esc_attr( GX_ZB_OPTION_SETTINGS ); ?>[client_id]" value="<?php echo esc_attr( $value ); ?>" class="regular-text">
		<?php
	}

	public function field_client_secret(): void {
		$settings = GX_ZB_Settings::instance()->all();
		$secret   = isset( $settings['client_secret'] ) ? $settings['client_secret'] : '';
		?>
		<input type="password" name="<?php echo esc_attr( GX_ZB_OPTION_SETTINGS ); ?>[client_secret]" value="" class="regular-text" placeholder="<?php echo ! empty( $secret ) ? esc_attr( '••••••••' ) : ''; ?>">
		<?php if ( ! empty( $secret ) ) : ?>
			<p class="description"><?php esc_html_e( 'A secret is saved. Leave blank to keep it unchanged.', 'gx-zoho-bookings' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function field_cache_minutes(): void {
		$settings = GX_ZB_Settings::instance()->all();
		$value    = isset( $settings['cache_minutes'] ) ? $settings['cache_minutes'] : 15;
		?>
		<input type="number" name="<?php echo esc_attr( GX_ZB_OPTION_SETTINGS ); ?>[cache_minutes]" value="<?php echo esc_attr( $value ); ?>" min="1" max="1440" step="1" class="small-text">
		<p class="description"><?php esc_html_e( 'How long API responses are cached (1‑1440 minutes).', 'gx-zoho-bookings' ); ?></p>
		<?php
	}

	public function field_redirect_uri(): void {
		$oauth  = GX_ZB_OAuth::instance();
		$uri    = $oauth->redirect_uri();
		?>
		<input type="text" readonly value="<?php echo esc_url( $uri ); ?>" class="regular-text gx-zb-copy-field" id="gx-zb-redirect-uri" onClick="this.select();">
		<button type="button" class="button gx-zb-copy-btn" data-target="gx-zb-redirect-uri"><?php esc_html_e( 'Copy', 'gx-zoho-bookings' ); ?></button>
		<p class="description"><?php esc_html_e( 'Add this Redirect URI in your Zoho API Console when creating the Server‑based Application.', 'gx-zoho-bookings' ); ?></p>
		<?php
	}

	public function enqueue_assets( $hook ): void {
		if ( 'settings_page_gx-zoho-bookings' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'gx-zb-admin',
			GX_ZB_URL . 'assets/css/gx-zb-admin.css',
			array(),
			GX_ZB_VERSION
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'settings';
		?>
		<div class="wrap gx-zb-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', admin_url( 'options-general.php?page=gx-zoho-bookings' ) ) ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'gx-zoho-bookings' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'connection', admin_url( 'options-general.php?page=gx-zoho-bookings' ) ) ); ?>" class="nav-tab <?php echo 'connection' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Connection', 'gx-zoho-bookings' ); ?>
				</a>
			</nav>

			<div class="gx-zb-tab-content">
				<?php
				if ( 'connection' === $active_tab ) {
					$this->render_connection_tab();
				} else {
					$this->render_settings_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	private function render_connection_tab(): void {
		$oauth    = GX_ZB_OAuth::instance();
		$settings = GX_ZB_Settings::instance()->all();
		$tokens   = get_option( GX_ZB_OPTION_TOKENS, array() );

		$is_connected = $oauth->is_connected();
		$connected_at = isset( $tokens['connected_at'] ) ? (int) $tokens['connected_at'] : 0;
		$region_label = GX_ZB_Regions::choices()[ $settings['region'] ] ?? $settings['region'];

		?>
		<div class="gx-zb-status-card <?php echo $is_connected ? 'is-connected' : 'is-disconnected'; ?>">
			<div class="gx-zb-status-indicator">
				<span class="gx-zb-status-dot"></span>
				<strong>
					<?php if ( $is_connected ) : ?>
						<?php esc_html_e( 'Connected to Zoho', 'gx-zoho-bookings' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Not connected', 'gx-zoho-bookings' ); ?>
					<?php endif; ?>
				</strong>
			</div>

			<?php if ( $is_connected && $connected_at ) : ?>
				<p class="gx-zb-connected-info">
					<?php
					printf(
						/* translators: %1$s: date, %2$s: region label */
						esc_html__( 'Connected since %1$s — Region: %2$s', 'gx-zoho-bookings' ),
						esc_html( date_i18n( get_option( 'date_format' ), $connected_at ) ),
						esc_html( $region_label )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'gx_zb_disconnect', 'gx_zb_disconnect_nonce' ); ?>
					<input type="hidden" name="action" value="gx_zb_disconnect">
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Disconnect', 'gx-zoho-bookings' ); ?></button>
				</form>
			<?php else : ?>
				<?php
				$authorize_url = '#';
				$disabled      = true;
				if ( ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] ) ) {
					$authorize_url = $oauth->authorize_url();
					$disabled      = false;
				}
				?>
				<p class="gx-zb-connect-hint">
					<?php if ( $disabled ) : ?>
						<?php esc_html_e( 'Save your Client ID and Client Secret first, then connect.', 'gx-zoho-bookings' ); ?>
					<?php endif; ?>
				</p>
				<a href="<?php echo $disabled ? '#' : esc_url( $authorize_url ); ?>" class="button button-primary"
				   <?php echo $disabled ? 'disabled' : ''; ?>>
					<?php esc_html_e( 'Connect to Zoho', 'gx-zoho-bookings' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
		// FUTURE (paid plan): team management / multiple workspace connection status would be shown here.
	}

	private function render_settings_tab(): void {
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'gx_zb_settings_group' );
			do_settings_sections( 'gx-zoho-bookings' );
			submit_button();
			?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'MCP API key', 'gx-zoho-bookings' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'gx_zb_mcp_regen_key', 'gx_zb_mcp_regen_nonce' ); ?>
			<input type="hidden" name="action" value="gx_zb_mcp_regen_key">
			<?php
			$has_key = '' !== (string) GX_ZB_Settings::instance()->get( 'mcp_api_key' );
			submit_button(
				$has_key ? __( 'Regenerate MCP API Key', 'gx-zoho-bookings' ) : __( 'Generate MCP API Key', 'gx-zoho-bookings' ),
				'secondary',
				'submit',
				false
			);
			?>
			<?php if ( $has_key ) : ?>
				<p class="description"><?php esc_html_e( 'Regenerating immediately disconnects every agent using the old key.', 'gx-zoho-bookings' ); ?></p>
			<?php endif; ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Service landing pages', 'gx-zoho-bookings' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Create one WordPress page per service, each with its own Stripe payment link. Re-run any time to refresh links and pick up new services.', 'gx-zoho-bookings' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'gx_zb_gen_pages', 'gx_zb_gen_pages_nonce' ); ?>
			<input type="hidden" name="action" value="gx_zb_gen_pages">
			<?php submit_button( __( 'Generate / refresh landing pages', 'gx-zoho-bookings' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
		$map = ( class_exists( 'GX_ZB_Service_Pages' ) ) ? GX_ZB_Service_Pages::instance()->get_map() : array();
		if ( ! empty( $map ) ) {
			echo '<ul class="gx-zb-page-list">';
			foreach ( $map as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$name = isset( $entry['name'] ) ? $entry['name'] : '';
				$purl = isset( $entry['page_url'] ) ? $entry['page_url'] : '';
				$pay  = isset( $entry['payment_url'] ) ? $entry['payment_url'] : '';
				echo '<li>';
				echo '<strong>' . esc_html( $name ) . '</strong> — ';
				if ( $purl ) {
					echo '<a href="' . esc_url( $purl ) . '" target="_blank" rel="noopener">' . esc_html__( 'landing page', 'gx-zoho-bookings' ) . '</a>';
				}
				if ( $pay ) {
					echo ' · <a href="' . esc_url( $pay ) . '" target="_blank" rel="noopener">' . esc_html__( 'payment link', 'gx-zoho-bookings' ) . '</a>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		?>

		<hr>
		<h2><?php esc_html_e( 'Cache', 'gx-zoho-bookings' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'gx_zb_flush_cache', 'gx_zb_flush_cache_nonce' ); ?>
			<input type="hidden" name="action" value="gx_zb_flush_cache">
			<?php submit_button( __( 'Flush API Cache', 'gx-zoho-bookings' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public function render_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_plugin_page  = ( 'settings_page_gx-zoho-bookings' === $screen->id );
		$is_plugins_page = ( 'plugins' === $screen->id );

		// Notices specific to our plugin page.
		if ( $is_plugin_page ) {
			$this->render_condition_notices();
			$this->render_oauth_result_notices();
			$this->render_cache_flush_notice();
		}

		// Missing setup warnings on the plugins list only — the settings page
		// already shows the more specific condition notices above.
		if ( $is_plugins_page ) {
			$this->render_setup_warnings();
		}

		// Reconnect flag anywhere in admin (persistent).
		if ( get_option( 'gx_zb_needs_reconnect' ) ) {
			$this->render_reconnect_notice();
		}
	}

	private function render_condition_notices(): void {
		$settings = GX_ZB_Settings::instance()->all();
		$mode     = $settings['mode'] ?? 'embed';
		$creds    = ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] );

		if ( 'api' === $mode && ! $creds ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %s: link to settings */
						esc_html__( 'Zoho Bookings is set to API mode but OAuth credentials are missing. Enter your Client ID and Secret in the %s.', 'gx-zoho-bookings' ),
						'<a href="' . esc_url( admin_url( 'options-general.php?page=gx-zoho-bookings' ) ) . '">' . esc_html__( 'settings', 'gx-zoho-bookings' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
		} elseif ( 'embed' === $mode && empty( $settings['embed_url'] ) ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<?php
					printf(
						esc_html__( 'Zoho Bookings is set to embed mode but no booking page URL is set. %s', 'gx-zoho-bookings' ),
						'<a href="' . esc_url( admin_url( 'options-general.php?page=gx-zoho-bookings' ) ) . '">' . esc_html__( 'Configure now', 'gx-zoho-bookings' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	private function render_oauth_result_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['gx_zb_error'] ) ) {
			$error = sanitize_text_field( wp_unslash( $_GET['gx_zb_error'] ) );
			switch ( $error ) {
				case 'oauth_denied':
					$message = __( 'Zoho authorization was declined.', 'gx-zoho-bookings' );
					break;
				case 'token_exchange_failed':
					$message = __( 'Token exchange failed. Please try again.', 'gx-zoho-bookings' );
					break;
				case 'bad_state':
					$message = __( 'Security check failed, retry.', 'gx-zoho-bookings' );
					break;
				default:
					$message = __( 'An unknown error occurred during OAuth.', 'gx-zoho-bookings' );
			}
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['gx_zb_connected'] ) && '1' === $_GET['gx_zb_connected'] ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Successfully connected to Zoho.', 'gx-zoho-bookings' ); ?></p>
			</div>
			<?php
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['gx_zb_disconnected'] ) && '1' === $_GET['gx_zb_disconnected'] ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Disconnected from Zoho. Stored tokens have been removed.', 'gx-zoho-bookings' ); ?></p>
			</div>
			<?php
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['gx_zb_key_regen'] ) && '1' === $_GET['gx_zb_key_regen'] ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'MCP API key generated. Copy it into your agent configuration — any previous key no longer works.', 'gx-zoho-bookings' ); ?></p>
			</div>
			<?php
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['gx_zb_gen_pages'] ) && 'done' === $_GET['gx_zb_gen_pages'] ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Service landing pages generated / refreshed. Each service now has its own page and payment link (see below).', 'gx-zoho-bookings' ); ?></p>
			</div>
			<?php
		}
	}

	private function render_cache_flush_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['gx_zb_cache_flushed'] ) && '1' === $_GET['gx_zb_cache_flushed'] ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'API cache flushed.', 'gx-zoho-bookings' ); ?></p>
			</div>
			<?php
		}
	}

	private function render_setup_warnings(): void {
		$settings = GX_ZB_Settings::instance()->all();
		$mode     = $settings['mode'] ?? 'embed';

		// Only show missing creds warning if API mode, not connected, and no creds.
		if ( 'api' === $mode && empty( $settings['client_id'] ) && empty( $settings['client_secret'] ) ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<?php
					printf(
						esc_html__( 'Zoho Bookings is not fully configured. %s', 'gx-zoho-bookings' ),
						'<a href="' . esc_url( admin_url( 'options-general.php?page=gx-zoho-bookings' ) ) . '">' . esc_html__( 'Go to Settings', 'gx-zoho-bookings' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
		} elseif ( 'embed' === $mode && empty( $settings['embed_url'] ) ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<?php
					printf(
						esc_html__( 'Zoho Bookings embed URL is missing. %s', 'gx-zoho-bookings' ),
						'<a href="' . esc_url( admin_url( 'options-general.php?page=gx-zoho-bookings' ) ) . '">' . esc_html__( 'Configure', 'gx-zoho-bookings' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Persistent notice shown while the stored Zoho token is invalid/revoked.
	 */
	private function render_reconnect_notice(): void {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s: reconnect link */
					esc_html__( 'Your Zoho Bookings connection has expired or been revoked. %s', 'gx-zoho-bookings' ),
					'<a href="' . esc_url( admin_url( 'options-general.php?page=gx-zoho-bookings&tab=connection' ) ) . '">' . esc_html__( 'Reconnect now', 'gx-zoho-bookings' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * admin-post handler: flush cached API responses.
	 */
	public function handle_flush_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'gx-zoho-bookings' ) );
		}
		check_admin_referer( 'gx_zb_flush_cache', 'gx_zb_flush_cache_nonce' );

		GX_ZB_API_Client::instance()->flush_cache();

		wp_safe_redirect( admin_url( 'options-general.php?page=gx-zoho-bookings&gx_zb_cache_flushed=1' ) );
		exit;
	}

	/**
	 * admin-post handler: revoke tokens and disconnect from Zoho.
	 */
	public function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'gx-zoho-bookings' ) );
		}
		check_admin_referer( 'gx_zb_disconnect', 'gx_zb_disconnect_nonce' );

		GX_ZB_OAuth::instance()->disconnect();

		wp_safe_redirect( admin_url( 'options-general.php?page=gx-zoho-bookings&tab=connection&gx_zb_disconnected=1' ) );
		exit;
	}
}
