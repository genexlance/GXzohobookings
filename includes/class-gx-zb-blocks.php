<?php
/**
 * Block registration.
 *
 * Both blocks are dynamic: the editor uses ServerSideRender previews and the
 * front end reuses the exact same render paths as the shortcodes, so output
 * can never drift between the two.
 *
 * @package GX_Zoho_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the embed and services blocks.
 */
final class GX_ZB_Blocks {

	/**
	 * Singleton instance.
	 *
	 * @var GX_ZB_Blocks|null
	 */
	private static $instance = null;

	/**
	 * Get the shared instance.
	 *
	 * @return GX_ZB_Blocks
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook block registration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register both block types from their block.json metadata.
	 *
	 * @return void
	 */
	public function register_blocks() {
		register_block_type(
			GX_ZB_DIR . 'blocks/embed',
			array( 'render_callback' => array( $this, 'render_embed' ) )
		);
		register_block_type(
			GX_ZB_DIR . 'blocks/services',
			array( 'render_callback' => array( $this, 'render_services' ) )
		);
		register_block_type(
			GX_ZB_DIR . 'blocks/service',
			array( 'render_callback' => array( $this, 'render_service' ) )
		);
		register_block_type(
			GX_ZB_DIR . 'blocks/book',
			array( 'render_callback' => array( $this, 'render_book' ) )
		);
	}

	/**
	 * Render callback for gx-zoho-bookings/book (native booking form).
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_book( $attributes ) {
		return GX_ZB_Payments::instance()->shortcode(
			array(
				'service'       => isset( $attributes['serviceId'] ) ? $attributes['serviceId'] : '',
				'show_phone'    => ( ! isset( $attributes['showPhone'] ) || $attributes['showPhone'] ) ? 'yes' : 'no',
				'require_phone' => ( ! isset( $attributes['requirePhone'] ) || $attributes['requirePhone'] ) ? 'yes' : 'no',
				'show_notes'    => ( ! empty( $attributes['showNotes'] ) ) ? 'yes' : 'no',
			)
		);
	}

	/**
	 * Render callback for gx-zoho-bookings/service (single service landing card).
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_service( $attributes ) {
		if ( ! class_exists( 'GX_ZB_Service_Pages' ) ) {
			return '';
		}
		return GX_ZB_Service_Pages::instance()->shortcode(
			array(
				'id' => isset( $attributes['serviceId'] ) ? $attributes['serviceId'] : '',
			)
		);
	}

	/**
	 * Render callback for gx-zoho-bookings/embed.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_embed( $attributes ) {
		return GX_ZB_Shortcodes::render_embed(
			array(
				'url'    => isset( $attributes['url'] ) ? $attributes['url'] : '',
				'height' => isset( $attributes['height'] ) ? $attributes['height'] : 750,
			)
		);
	}

	/**
	 * Render callback for gx-zoho-bookings/services.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_services( $attributes ) {
		return GX_ZB_Shortcodes::render_services(
			array(
				'workspace'        => isset( $attributes['workspace'] ) ? $attributes['workspace'] : '',
				'columns'          => isset( $attributes['columns'] ) ? $attributes['columns'] : 3,
				'show_description' => ( ! isset( $attributes['showDescription'] ) || $attributes['showDescription'] ) ? 'yes' : 'no',
				'show_duration'    => ( ! isset( $attributes['showDuration'] ) || $attributes['showDuration'] ) ? 'yes' : 'no',
				'book_label'       => isset( $attributes['bookLabel'] ) ? $attributes['bookLabel'] : '',
				'pay_label'        => isset( $attributes['payLabel'] ) ? $attributes['payLabel'] : '',
			)
		);
	}
}
