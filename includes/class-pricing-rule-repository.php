<?php
/**
 * Read operations for pricing rules.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queries the pricing_rules, rule_products, and rule_categories tables to resolve
 * applicable discount rules for a given product and user.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Pricing_Rule_Repository {
	private static $instance = null;
	private $db;
	private $user_groups;

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Pricing_Rule_Repository
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->db          = WCCG_Database::instance();
		$this->user_groups = WCCG_User_Group_Repository::instance();
	}

	/**
	 * Get the applicable pricing rule for a product and user.
	 *
	 * Resolves the user's group (falling back to the default group if set), then
	 * returns a product-specific rule if one exists, otherwise the best category rule.
	 *
	 * @since  1.0.0
	 * @param  int $product_id WooCommerce product or variation ID.
	 * @param  int $user_id    WordPress user ID (0 for guest).
	 * @return object|null stdClass rule row, or null if no active rule applies.
	 */
	public function get_pricing_rule_for_product( $product_id, $user_id ) {
		$group_id = $this->user_groups->get_user_group( $user_id );

		if ( ! $group_id ) {
			$default_group_id = get_option( 'wccg_default_group_id', 0 );
			if ( $default_group_id ) {
				$group_id = $default_group_id;
			} else {
				return null;
			}
		}

		$product_rule = $this->get_product_specific_rule( $product_id, $group_id );
		if ( $product_rule ) {
			return $product_rule;
		}

		return $this->get_best_category_rule( $product_id, $group_id );
	}

	/**
	 * Delete all pricing rules and their product/category associations for a group.
	 *
	 * @since  1.0.0
	 * @param  int $group_id Customer group ID.
	 * @return bool True on success, false on transaction failure.
	 */
	public function delete_group_pricing_rules( $group_id ) {
		return $this->db->transaction(
			function () use ( $group_id ) {
				global $wpdb;

				$rules = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT rule_id FROM {$this->db->get_table_name('pricing_rules')} WHERE group_id = %d",
						$group_id
					)
				);

				foreach ( $rules as $rule_id ) {
					$wpdb->delete( $this->db->get_table_name( 'rule_products' ), array( 'rule_id' => $rule_id ), array( '%d' ) );
					$wpdb->delete( $this->db->get_table_name( 'rule_categories' ), array( 'rule_id' => $rule_id ), array( '%d' ) );
				}

				return $wpdb->delete( $this->db->get_table_name( 'pricing_rules' ), array( 'group_id' => $group_id ), array( '%d' ) );
			}
		);
	}

	/**
	 * Fetch pricing rules for the Pricing Rules admin page.
	 *
	 * @since  1.0.0
	 * @return object[] Array of rules keyed by rule ID.
	 */
	public function get_pricing_rules_for_admin_page() {
		global $wpdb;

		$column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'sort_order'",
				DB_NAME,
				$wpdb->prefix . 'pricing_rules'
			)
		);

		if ( ! empty( $column_exists ) ) {
			return $wpdb->get_results(
				"SELECT pr.*, GROUP_CONCAT(DISTINCT rp.product_id) as product_ids, GROUP_CONCAT(DISTINCT rc.category_id) as category_ids
                FROM {$wpdb->prefix}pricing_rules pr
                LEFT JOIN {$wpdb->prefix}rule_products rp ON pr.rule_id = rp.rule_id
                LEFT JOIN {$wpdb->prefix}rule_categories rc ON pr.rule_id = rc.rule_id
                GROUP BY pr.rule_id
                ORDER BY pr.sort_order ASC, pr.rule_id ASC",
				OBJECT_K
			);
		}

		return $wpdb->get_results(
			"SELECT pr.*, GROUP_CONCAT(DISTINCT rp.product_id) as product_ids, GROUP_CONCAT(DISTINCT rc.category_id) as category_ids
            FROM {$wpdb->prefix}pricing_rules pr
            LEFT JOIN {$wpdb->prefix}rule_products rp ON pr.rule_id = rp.rule_id
            LEFT JOIN {$wpdb->prefix}rule_categories rc ON pr.rule_id = rc.rule_id
            GROUP BY pr.rule_id
            ORDER BY pr.rule_id ASC",
			OBJECT_K
		);
	}

	/**
	 * Fetch all pricing rules applied directly to a product.
	 *
	 * @since  1.0.0
	 * @param  int $product_id Product post ID.
	 * @return object[] Array of matching rule rows.
	 */
	public function get_product_pricing_rules( $product_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pr.*, g.group_name
            FROM {$wpdb->prefix}pricing_rules pr
            JOIN {$wpdb->prefix}rule_products rp ON pr.rule_id = rp.rule_id
            JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
            WHERE rp.product_id = %d
            ORDER BY pr.created_at DESC",
				$product_id
			)
		);
	}

	/**
	 * Fetch all pricing rules applied to a product category.
	 *
	 * @since  1.0.0
	 * @param  int $category_id Product category term ID.
	 * @return object[] Array of matching rule rows.
	 */
	public function get_category_pricing_rules( $category_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pr.*, g.group_name, t.name as category_name
            FROM {$wpdb->prefix}pricing_rules pr
            JOIN {$wpdb->prefix}rule_categories rc ON pr.rule_id = rc.rule_id
            JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
            JOIN {$wpdb->prefix}terms t ON rc.category_id = t.term_id
            WHERE rc.category_id = %d
            ORDER BY pr.created_at DESC",
				$category_id
			)
		);
	}

	/**
	 * Determine whether a pricing rule exists.
	 *
	 * @since  1.0.0
	 * @param  int $rule_id Pricing rule ID.
	 * @return bool True when the rule exists.
	 */
	public function pricing_rule_exists( $rule_id ) {
		global $wpdb;

		$rule_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT rule_id FROM {$wpdb->prefix}pricing_rules WHERE rule_id = %d",
				$rule_id
			)
		);

		return ! empty( $rule_exists );
	}

	/**
	 * Fetch the active status for a pricing rule.
	 *
	 * @since  1.0.0
	 * @param  int $rule_id Pricing rule ID.
	 * @return int|null Status value, or null when the rule is missing.
	 */
	public function get_pricing_rule_status( $rule_id ) {
		global $wpdb;

		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT is_active FROM {$wpdb->prefix}pricing_rules WHERE rule_id = %d",
				$rule_id
			)
		);

		return null !== $status ? (int) $status : null;
	}

	/**
	 * Fetch a pricing rule row by ID.
	 *
	 * @since  1.0.0
	 * @param  int $rule_id Pricing rule ID.
	 * @return object|null Rule row, or null when not found.
	 */
	public function get_pricing_rule( $rule_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}pricing_rules WHERE rule_id = %d",
				$rule_id
			)
		);
	}

	/**
	 * Fetch related product IDs for a pricing rule.
	 *
	 * @since  1.0.0
	 * @param  int $rule_id Pricing rule ID.
	 * @return array Array of product IDs.
	 */
	public function get_pricing_rule_product_ids( $rule_id ) {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT product_id FROM {$wpdb->prefix}rule_products WHERE rule_id = %d",
				$rule_id
			)
		);
	}

	/**
	 * Fetch related category IDs for a pricing rule.
	 *
	 * @since  1.0.0
	 * @param  int $rule_id Pricing rule ID.
	 * @return array Array of category IDs.
	 */
	public function get_pricing_rule_category_ids( $rule_id ) {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT category_id FROM {$wpdb->prefix}rule_categories WHERE rule_id = %d",
				$rule_id
			)
		);
	}

	/**
	 * Get all product category IDs (including ancestor categories) for a product.
	 *
	 * For variation products, uses the parent product's categories.
	 *
	 * @since  1.0.0
	 * @param  int $product_id WooCommerce product ID.
	 * @return int[] Unique array of category term IDs.
	 */
	public function get_all_product_categories( $product_id ) {
		$category_ids = array();
		$product      = wc_get_product( $product_id );

		if ( $product && $product->is_type( 'variation' ) ) {
			$product_id = $product->get_parent_id();
		}

		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				$category_ids[] = $term->term_id;
				$ancestors      = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
				if ( ! empty( $ancestors ) ) {
					$category_ids = array_merge( $category_ids, $ancestors );
				}
			}
		}

		return array_unique( $category_ids );
	}

	private function get_product_specific_rule( $product_id, $group_id ) {
		global $wpdb;

		$product_ids = array( $product_id );
		$product     = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$product_ids[] = $parent_id;
			}
		}

		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$query_args   = array_merge( array( $group_id ), $product_ids );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT pr.*
            FROM {$this->db->get_table_name('pricing_rules')} pr
            JOIN {$this->db->get_table_name('rule_products')} rp ON pr.rule_id = rp.rule_id
            WHERE pr.group_id = %d AND rp.product_id IN ($placeholders) AND pr.is_active = 1
            AND (pr.start_date IS NULL OR pr.start_date <= UTC_TIMESTAMP())
            AND (pr.end_date IS NULL OR pr.end_date >= UTC_TIMESTAMP())
            ORDER BY FIELD(rp.product_id, " . implode( ',', $product_ids ) . ')
            LIMIT 1',
				$query_args
			)
		);
	}

	private function get_best_category_rule( $product_id, $group_id ) {
		$category_ids = $this->get_all_product_categories( $product_id );
		if ( empty( $category_ids ) ) {
			return null;
		}

		$category_rules = $this->get_category_rules( $category_ids, $group_id );
		if ( empty( $category_rules ) ) {
			return null;
		}

		return $this->determine_best_rule( $category_rules );
	}

	private function get_category_rules( $category_ids, $group_id ) {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );
		$query        = $wpdb->prepare(
			"SELECT pr.*, rc.category_id
            FROM {$this->db->get_table_name('pricing_rules')} pr
            JOIN {$this->db->get_table_name('rule_categories')} rc ON pr.rule_id = rc.rule_id
            WHERE pr.group_id = %d AND rc.category_id IN ($placeholders) AND pr.is_active = 1
            AND (pr.start_date IS NULL OR pr.start_date <= UTC_TIMESTAMP())
            AND (pr.end_date IS NULL OR pr.end_date >= UTC_TIMESTAMP())
            ORDER BY pr.created_at DESC",
			array_merge( array( $group_id ), $category_ids )
		);

		return $wpdb->get_results( $query );
	}

	private function determine_best_rule( $rules ) {
		$best_rule = null;

		foreach ( $rules as $rule ) {
			if ( ! $best_rule ) {
				$best_rule = $rule;
				continue;
			}

			if ( $this->compare_discount_rules( $rule, $best_rule ) > 0 ) {
				$best_rule = $rule;
			}
		}

		return $best_rule;
	}

	private function compare_discount_rules( $rule1, $rule2 ) {
		// Fixed discounts take precedence over percentage discounts of equal value.
		if ( $rule1->discount_type !== $rule2->discount_type ) {
			return $rule1->discount_type === 'fixed' ? 1 : -1;
		}

		if ( $rule1->discount_value === $rule2->discount_value ) {
			return strtotime( $rule1->created_at ) > strtotime( $rule2->created_at ) ? 1 : -1;
		}

		return $rule1->discount_value > $rule2->discount_value ? 1 : -1;
	}
}
