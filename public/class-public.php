<?php
/**
 * Frontend functionality coordinator.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instantiates and wires up the pricing and banner sub-classes for the frontend.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Public {
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Public|null
	 */
	private static $instance = null;

	/**
	 * Frontend pricing service.
	 *
	 * @var WCCG_Public_Pricing
	 */
	private $pricing;

	/**
	 * Frontend banner service.
	 *
	 * @var WCCG_Public_Banner
	 */
	private $banner;

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Public
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize frontend dependencies and hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function __construct() {
		$this->pricing = WCCG_Public_Pricing::instance();
		$this->banner  = WCCG_Public_Banner::instance();
		$this->init_hooks();
	}

	/**
	 * Register frontend WooCommerce and theme hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function init_hooks() {
		/**
		 * Fires before WooCommerce recalculates cart totals.
		 *
		 * @since 1.0.0
		 *
		 * @param WC_Cart $cart The WooCommerce cart object.
		 */
		add_action( 'woocommerce_before_calculate_totals', array( $this->pricing, 'adjust_cart_prices' ), 10, 1 );

		/**
		 * Filters the displayed price HTML for simple products.
		 *
		 * @since 1.0.0
		 *
		 * @param string     $price_html The existing formatted price HTML.
		 * @param WC_Product $product    The product being rendered.
		 */
		add_filter( 'woocommerce_get_price_html', array( $this->pricing, 'adjust_price_display' ), 10, 2 );

		/**
		 * Filters the displayed price range HTML for variable products.
		 *
		 * @since 1.0.0
		 *
		 * @param string     $price_html The existing formatted price HTML.
		 * @param WC_Product $product    The variable product being rendered.
		 */
		add_filter( 'woocommerce_variable_price_html', array( $this->pricing, 'adjust_variable_price_html' ), 10, 2 );

		/**
		 * Filters variation data returned to the frontend when a variation is selected.
		 *
		 * @since 1.0.0
		 *
		 * @param array      $variation_data Variation data prepared for JavaScript.
		 * @param WC_Product $product        The parent variable product.
		 * @param WC_Product $variation      The selected variation product.
		 */
		add_filter( 'woocommerce_available_variation', array( $this->pricing, 'adjust_variation_data' ), 10, 3 );

		/**
		 * Filters the displayed price HTML for a specific variation.
		 *
		 * @since 1.0.0
		 *
		 * @param string     $price_html The existing formatted price HTML.
		 * @param WC_Product $variation  The variation product being rendered.
		 */
		add_filter( 'woocommerce_variation_price_html', array( $this->pricing, 'adjust_variation_price_html' ), 10, 2 );

		/**
		 * Filters the cart item unit price HTML.
		 *
		 * @since 1.0.0
		 *
		 * @param string $price_html    The existing formatted item price HTML.
		 * @param array  $cart_item     The cart item data array.
		 * @param string $cart_item_key The cart item key.
		 */
		add_filter( 'woocommerce_cart_item_price', array( $this->pricing, 'display_cart_item_price' ), 10, 3 );

		/**
		 * Filters the cart item subtotal HTML.
		 *
		 * @since 1.0.0
		 *
		 * @param string $subtotal_html The existing formatted subtotal HTML.
		 * @param array  $cart_item     The cart item data array.
		 * @param string $cart_item_key The cart item key.
		 */
		add_filter( 'woocommerce_cart_item_subtotal', array( $this->pricing, 'display_cart_item_subtotal' ), 10, 3 );

		/**
		 * Fires near the opening of the page body so the sticky pricing banner can render early.
		 *
		 * @since 1.0.0
		 */
		add_action( 'wp_body_open', array( $this->banner, 'display_sticky_banner' ), 10 );

		/**
		 * Fires in the footer as a fallback when the active theme does not call wp_body_open().
		 *
		 * @since 1.0.0
		 */
		add_action( 'wp_footer', array( $this->banner, 'display_sticky_banner_fallback' ), 5 );

		/**
		 * Fires when frontend scripts and styles should be enqueued.
		 *
		 * @since 1.0.0
		 */
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Enqueue the plugin's frontend stylesheet.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function enqueue_styles() {
		wp_enqueue_style( 'wccg-public-styles', WCCG_URL . 'assets/css/public.css', array(), WCCG_VERSION );
	}

	/**
	 * Get the display title for the current user's pricing group (or default group).
	 *
	 * @since  1.1.0
	 * @param  int|null $user_id WordPress user ID, or null to use the current user.
	 * @return string|null Group display title, or null if the user has no active group/rule.
	 */
	public function get_pricing_group_display_title( $user_id = null ) {
		return $this->banner->get_pricing_group_display_title( $user_id );
	}
}
