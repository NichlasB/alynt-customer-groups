<?php
/**
 * Frontend price adjustments for grouped customers.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies customer group discounts to WooCommerce product prices in the catalog, cart, and checkout.
 *
 * Prices are cached per product/user pair within the request to avoid redundant database lookups.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Public_Pricing {
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Public_Pricing|null
	 */
	private static $instance = null;

	/**
	 * Database facade.
	 *
	 * @var WCCG_Database
	 */
	private $db;

	/**
	 * Shared utility helper.
	 *
	 * @var WCCG_Utilities
	 */
	private $utils;

	/**
	 * Cached adjusted prices by product/user key.
	 *
	 * @var array<string,float|false>
	 */
	private $price_cache = array();

	/**
	 * Cached group label HTML by user/default key.
	 *
	 * @var array<string,string>
	 */
	private $group_label_cache = array();

	/**
	 * Tracks variable products primed for rule lookup.
	 *
	 * @var array<string,bool>
	 */
	private $primed_variable_products = array();

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Public_Pricing
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize pricing dependencies.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function __construct() {
		$this->db    = WCCG_Database::instance();
		$this->utils = WCCG_Utilities::instance();
	}

	/**
	 * Apply group discounts to all cart item prices before totals are calculated.
	 *
	 * Skips the adjustment if it has already been applied this request to prevent double-discounting.
	 *
	 * @since  1.0.0
	 * @param  WC_Cart $cart The WooCommerce cart object.
	 * @return void
	 */
	public function adjust_cart_prices( $cart ) {
		if ( is_admin() ) {
			return;
		}

		if ( ! empty( $cart->wccg_prices_adjusted ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$product        = $cart_item['data'];
			$adjusted_price = $this->get_adjusted_price( $product );
			if ( $adjusted_price !== false ) {
				$product->set_price( $adjusted_price );
			}
		}

		$cart->wccg_prices_adjusted = true;
	}

	/**
	 * Filter the price HTML for a simple (or variable) product in the catalog.
	 *
	 * Outputs a strikethrough original price, the discounted price, and a group label.
	 *
	 * @since  1.0.0
	 * @param  string     $price_html The existing price HTML.
	 * @param  WC_Product $product    The product object.
	 * @return string Modified price HTML, or the original if no discount applies.
	 */
	public function adjust_price_display( $price_html, $product ) {
		if ( $product->is_type( 'variable' ) ) {
			return $this->adjust_variable_price_display( $price_html, $product );
		}

		$adjusted_price = $this->get_adjusted_price( $product );
		if ( $adjusted_price === false ) {
			return $price_html;
		}

		$original_price = $this->get_base_price( $product );
		if ( $adjusted_price >= $original_price ) {
			return $price_html;
		}

		return sprintf(
			'<del>%s</del> <ins>%s</ins>%s',
			wc_price( $original_price ),
			wc_price( $adjusted_price ),
			$this->build_group_label_html( get_current_user_id() )
		);
	}

	/**
	 * Filter the price range HTML for a variable product.
	 *
	 * @since  1.0.0
	 * @param  string     $price_html The existing price HTML.
	 * @param  WC_Product $product    The variable product object.
	 * @return string Modified price HTML, or the original if no discount applies.
	 */
	public function adjust_variable_price_html( $price_html, $product ) {
		return $this->adjust_variable_price_display( $price_html, $product );
	}

	/**
	 * Filter variation data returned via AJAX when a customer selects a variation.
	 *
	 * Updates display_price, display_regular_price, and price_html in the variation JSON.
	 *
	 * @since  1.0.0
	 * @param  array      $variation_data The variation data array.
	 * @param  WC_Product $product        The parent variable product.
	 * @param  WC_Product $variation      The variation product object.
	 * @return array Modified variation data.
	 */
	public function adjust_variation_data( $variation_data, $product, $variation ) {
		$user_id           = get_current_user_id();
		$effective_user_id = $user_id ? $user_id : 0;
		$this->prime_variable_product_rules( $product, $effective_user_id );
		$pricing_rule = $this->db->get_pricing_rule_for_product( $variation->get_id(), $effective_user_id );
		if ( ! $pricing_rule ) {
			return $variation_data;
		}

		$original_price   = $variation_data['display_price'];
		$discounted_price = $this->calculate_discounted_price( $original_price, $pricing_rule );
		if ( $discounted_price >= $original_price ) {
			return $variation_data;
		}

		$variation_data['display_price']         = $discounted_price;
		$variation_data['display_regular_price'] = $original_price;
		$variation_data['price_html']            = sprintf(
			'<del>%s</del> <ins>%s</ins>%s',
			wc_price( $original_price ),
			wc_price( $discounted_price ),
			$this->build_group_label_html( $user_id )
		);

		return $variation_data;
	}

	/**
	 * Filter the price HTML shown for a single variation on the product page.
	 *
	 * @since  1.0.0
	 * @param  string     $price_html The existing price HTML.
	 * @param  WC_Product $variation  The variation product object.
	 * @return string Modified price HTML, or the original if no discount applies.
	 */
	public function adjust_variation_price_html( $price_html, $variation ) {
		$adjusted_price = $this->get_adjusted_price( $variation );
		if ( $adjusted_price === false ) {
			return $price_html;
		}

		$original_price = $this->get_base_price( $variation );
		if ( empty( $original_price ) || $adjusted_price >= $original_price ) {
			return $price_html;
		}

		return sprintf(
			'<del>%s</del> <ins>%s</ins>%s',
			wc_price( $original_price ),
			wc_price( $adjusted_price ),
			$this->build_group_label_html( get_current_user_id() )
		);
	}

	/**
	 * Filter the unit price HTML in the cart item price column.
	 *
	 * @since  1.0.0
	 * @param  string $price_html    The existing price HTML.
	 * @param  array  $cart_item     The cart item array.
	 * @param  string $cart_item_key The cart item key.
	 * @return string Modified price HTML, or the original if no discount applies.
	 */
	public function display_cart_item_price( $price_html, $cart_item, $cart_item_key ) {
		unset( $cart_item_key );
		$product        = $cart_item['data'];
		$adjusted_price = $this->get_adjusted_price( $product );
		if ( $adjusted_price === false ) {
			return $price_html;
		}

		$original_price = $this->get_base_price( $product );
		if ( $adjusted_price < $original_price ) {
			return sprintf( '<del>%s</del> <ins>%s</ins>', wc_price( $original_price ), wc_price( $adjusted_price ) );
		}

		return $price_html;
	}

	/**
	 * Filter the line-item subtotal HTML in the cart subtotal column.
	 *
	 * @since  1.0.0
	 * @param  string $subtotal_html The existing subtotal HTML.
	 * @param  array  $cart_item     The cart item array.
	 * @param  string $cart_item_key The cart item key.
	 * @return string Modified subtotal HTML, or the original if no discount applies.
	 */
	public function display_cart_item_subtotal( $subtotal_html, $cart_item, $cart_item_key ) {
		unset( $cart_item_key );
		$product        = $cart_item['data'];
		$adjusted_price = $this->get_adjusted_price( $product );
		if ( $adjusted_price === false ) {
			return $subtotal_html;
		}

		$original_price = $this->get_base_price( $product );
		$quantity       = $cart_item['quantity'];
		if ( $adjusted_price < $original_price ) {
			return sprintf( '<del>%s</del> <ins>%s</ins>', wc_price( $original_price * $quantity ), wc_price( $adjusted_price * $quantity ) );
		}

		return $subtotal_html;
	}

	/**
	 * Build discounted price HTML for a variable product.
	 *
	 * @since  1.0.0
	 * @param  string     $price_html Existing price HTML.
	 * @param  WC_Product $product    Variable product object.
	 * @return string
	 */
	private function adjust_variable_price_display( $price_html, $product ) {
		$user_id           = get_current_user_id();
		$effective_user_id = $user_id ? $user_id : 0;
		$this->prime_variable_product_rules( $product, $effective_user_id );
		$group_id = $user_id ? $this->db->get_user_group( $user_id ) : null;
		if ( ! $group_id ) {
			$group_id = get_option( 'wccg_default_group_id', 0 );
		}
		if ( ! $group_id ) {
			return $price_html;
		}

		$variation_prices = $product->get_variation_prices( true );
		if ( empty( $variation_prices['price'] ) ) {
			return $price_html;
		}

		$min_price    = min( $variation_prices['price'] );
		$max_price    = max( $variation_prices['price'] );
		$pricing_rule = $this->db->get_pricing_rule_for_product( $product->get_id(), $effective_user_id );
		if ( ! $pricing_rule ) {
			return $price_html;
		}

		$discounted_min = $this->calculate_discounted_price( $min_price, $pricing_rule );
		$discounted_max = $this->calculate_discounted_price( $max_price, $pricing_rule );
		if ( $discounted_min >= $min_price && $discounted_max >= $max_price ) {
			return $price_html;
		}

		$label_html = $this->build_group_label_html( $user_id );
		if ( $min_price === $max_price ) {
			return sprintf( '<del>%s</del> <ins>%s</ins>%s', wc_price( $min_price ), wc_price( $discounted_min ), $label_html );
		}

		return sprintf( '<del>%1$s &ndash; %2$s</del> <ins>%3$s &ndash; %4$s</ins>%5$s', wc_price( $min_price ), wc_price( $max_price ), wc_price( $discounted_min ), wc_price( $discounted_max ), $label_html );
	}

	/**
	 * Return an adjusted product price for the current customer context.
	 *
	 * @since  1.0.0
	 * @param  WC_Product $product Product object.
	 * @return float|false
	 */
	private function get_adjusted_price( $product ) {
		$user_id           = get_current_user_id();
		$effective_user_id = $user_id ? $user_id : 0;
		$cache_key         = $product->get_id() . '_' . $effective_user_id;
		if ( isset( $this->price_cache[ $cache_key ] ) ) {
			return $this->price_cache[ $cache_key ];
		}

		$original_price = $this->get_base_price( $product );
		if ( empty( $original_price ) ) {
			$this->price_cache[ $cache_key ] = false;
			return false;
		}

		$pricing_rule = $this->db->get_pricing_rule_for_product( $product->get_id(), $effective_user_id );
		if ( ! $pricing_rule ) {
			$this->price_cache[ $cache_key ] = false;
			return false;
		}

		$adjusted_price                  = $this->calculate_discounted_price( $original_price, $pricing_rule );
		$this->price_cache[ $cache_key ] = $adjusted_price;
		return $adjusted_price;
	}

	/**
	 * Calculate the discounted price for a rule.
	 *
	 * @since  1.0.0
	 * @param  float  $original_price Original product price.
	 * @param  object $pricing_rule   Pricing rule object.
	 * @return float
	 */
	private function calculate_discounted_price( $original_price, $pricing_rule ) {
		$discount_amount = 'percentage' === $pricing_rule->discount_type
			? ( $pricing_rule->discount_value / 100 ) * $original_price
			: $pricing_rule->discount_value;

		$adjusted_price = $original_price - $discount_amount;
		if ( $adjusted_price < 0 ) {
			$this->utils->log_error(
				'Negative price calculated',
				array(
					'original_price' => $original_price,
					'discount_type'  => $pricing_rule->discount_type,
					'discount_value' => $pricing_rule->discount_value,
				)
			);
			return 0;
		}

		return $adjusted_price;
	}

	/**
	 * Return the base product price before group adjustments.
	 *
	 * @since  1.0.0
	 * @param  WC_Product $product Product object.
	 * @return string
	 */
	private function get_base_price( $product ) {
		$sale_price = $product->get_sale_price();
		return ! empty( $sale_price ) && $sale_price > 0 ? $sale_price : $product->get_regular_price();
	}

	/**
	 * Build the HTML label showing the applicable pricing group.
	 *
	 * @since  1.0.0
	 * @param  int $user_id Current user ID, or 0 for guests/default pricing.
	 * @return string
	 */
	private function build_group_label_html( $user_id ) {
		$cache_key = $user_id > 0 ? 'user:' . absint( $user_id ) : 'default';
		if ( array_key_exists( $cache_key, $this->group_label_cache ) ) {
			return $this->group_label_cache[ $cache_key ];
		}

		$group_name = $user_id ? $this->db->get_user_group_name( $user_id ) : null;
		if ( ! $group_name && get_option( 'wccg_default_group_id', 0 ) ) {
			$custom_title = get_option( 'wccg_default_group_custom_title', '' );
			if ( ! empty( $custom_title ) ) {
				$group_name = $custom_title;
			} else {
				global $wpdb;
				$group_name = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be parameterized; placeholders are used for dynamic values.
						'SELECT group_name FROM ' . $wpdb->prefix . 'customer_groups WHERE group_id = %d',
						get_option( 'wccg_default_group_id', 0 )
					)
				);
			}
		}

		if ( ! $group_name ) {
			$this->group_label_cache[ $cache_key ] = '';
			return $this->group_label_cache[ $cache_key ];
		}

		$this->group_label_cache[ $cache_key ] = sprintf(
			' <span class="special-price-label">%s</span>',
			sprintf(
				/* translators: %s: pricing group name. */
				__( '%s Pricing', 'alynt-customer-groups' ),
				$this->utils->escape_output( $group_name )
			)
		);

		return $this->group_label_cache[ $cache_key ];
	}

	/**
	 * Prime pricing rules for a variable product and its children.
	 *
	 * @since  1.0.0
	 * @param  WC_Product $product           Variable product object.
	 * @param  int        $effective_user_id Effective user ID used for pricing lookup.
	 * @return void
	 */
	private function prime_variable_product_rules( $product, $effective_user_id ) {
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}

		$cache_key = $product->get_id() . '_' . absint( $effective_user_id );
		if ( isset( $this->primed_variable_products[ $cache_key ] ) ) {
			return;
		}

		$product_ids = array_merge( array( $product->get_id() ), $product->get_children() );
		$this->db->prime_pricing_rules_for_products( $product_ids, $effective_user_id );
		$this->primed_variable_products[ $cache_key ] = true;
	}
}
