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
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Pricing_Rule_Repository|null
	 */
	private static $instance = null;

	/**
	 * Database facade.
	 *
	 * @var WCCG_Database
	 */
	private $db;

	/**
	 * User-group repository.
	 *
	 * @var WCCG_User_Group_Repository
	 */
	private $user_groups;

	/**
	 * Cached effective group IDs keyed by user ID.
	 *
	 * @var array<int,int>
	 */
	private $effective_group_cache = array();

	/**
	 * Cached pricing rules keyed by product/group cache key.
	 *
	 * @var array<string,object|null>
	 */
	private $pricing_rule_cache = array();

	/**
	 * Cached category IDs keyed by product ID.
	 *
	 * @var array<int,int[]>
	 */
	private $product_categories_cache = array();

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

	/**
	 * Initialize repository dependencies.
	 *
	 * @since  1.0.0
	 * @return void
	 */
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
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return null;
		}

		$group_id = $this->get_effective_group_id( $user_id );
		if ( ! $group_id ) {
			return null;
		}

		$cache_key = $this->get_rule_cache_key( $product_id, $group_id );
		if ( array_key_exists( $cache_key, $this->pricing_rule_cache ) ) {
			return $this->pricing_rule_cache[ $cache_key ];
		}

		$this->prime_pricing_rules_for_products( array( $product_id ), $user_id );

		return array_key_exists( $cache_key, $this->pricing_rule_cache ) ? $this->pricing_rule_cache[ $cache_key ] : null;
	}

	/**
	 * Prime pricing rules for multiple products in a single request.
	 *
	 * @since  1.1.0
	 * @param  int[] $product_ids WooCommerce product or variation IDs.
	 * @param  int   $user_id     WordPress user ID (0 for guest).
	 * @return void
	 */
	public function prime_pricing_rules_for_products( $product_ids, $user_id ) {
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
		if ( empty( $product_ids ) ) {
			return;
		}

		$group_id = $this->get_effective_group_id( $user_id );
		if ( ! $group_id ) {
			foreach ( $product_ids as $product_id ) {
				$this->pricing_rule_cache[ $this->get_rule_cache_key( $product_id, 0 ) ] = null;
			}

			return;
		}

		$uncached_product_ids = array();
		foreach ( $product_ids as $product_id ) {
			$cache_key = $this->get_rule_cache_key( $product_id, $group_id );
			if ( ! array_key_exists( $cache_key, $this->pricing_rule_cache ) ) {
				$uncached_product_ids[] = $product_id;
			}
		}

		if ( empty( $uncached_product_ids ) ) {
			return;
		}

		$product_contexts          = $this->build_product_contexts( $uncached_product_ids );
		$product_rules_by_match_id = $this->get_product_specific_rules_for_products( $product_contexts, $group_id );
		$unresolved_contexts       = array();

		foreach ( $product_contexts as $product_id => $context ) {
			$product_rule = $this->select_product_specific_rule( $context, $product_rules_by_match_id );
			if ( $product_rule ) {
				$this->pricing_rule_cache[ $this->get_rule_cache_key( $product_id, $group_id ) ] = $product_rule;
				continue;
			}

			$unresolved_contexts[ $product_id ] = $context;
		}

		if ( empty( $unresolved_contexts ) ) {
			return;
		}

		$category_rules_by_product_id = $this->get_category_rules_for_contexts( $unresolved_contexts, $group_id );

		foreach ( $unresolved_contexts as $product_id => $context ) {
			$product_rules = isset( $category_rules_by_product_id[ $product_id ] ) ? $category_rules_by_product_id[ $product_id ] : array();
			$this->pricing_rule_cache[ $this->get_rule_cache_key( $product_id, $group_id ) ] = ! empty( $product_rules )
				? $this->determine_best_rule( $product_rules )
				: null;
		}
	}

	/**
	 * Delete all pricing rules and their product/category associations for a group.
	 *
	 * @since  1.0.0
	 * @param  int $group_id Customer group ID.
	 * @return bool True on success, false on transaction failure.
	 */
	public function delete_group_pricing_rules( $group_id ) {
		$rule_ids = array();
		$result   = $this->db->transaction(
			function () use ( $group_id, &$rule_ids ) {
				global $wpdb;

				$pricing_rules_table = $this->db->get_table_name( 'pricing_rules' );

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
				$rule_ids = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT rule_id FROM ' . $pricing_rules_table . ' WHERE group_id = %d',
						$group_id
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

				foreach ( $rule_ids as $rule_id ) {
					$wpdb->delete( $this->db->get_table_name( 'rule_products' ), array( 'rule_id' => $rule_id ), array( '%d' ) );
					$wpdb->delete( $this->db->get_table_name( 'rule_categories' ), array( 'rule_id' => $rule_id ), array( '%d' ) );
				}

				return $wpdb->delete( $this->db->get_table_name( 'pricing_rules' ), array( 'group_id' => $group_id ), array( '%d' ) );
			}
		);

		if ( false !== $result && ! empty( $rule_ids ) && class_exists( 'WCCG_Core' ) ) {
			WCCG_Core::instance()->refresh_price_caches_for_rule_ids( $rule_ids );
		}

		return $result;
	}

	/**
	 * Fetch pricing rules for the Pricing Rules admin page.
	 *
	 * @since  1.0.0
	 * @param  array $args Optional query arguments including limit and offset.
	 * @return object[] Array of rules keyed by rule ID.
	 */
	public function get_pricing_rules_for_admin_page( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'  => 0,
			'offset' => 0,
		);
		$args     = wp_parse_args( $args, $defaults );
		$limit    = absint( $args['limit'] );
		$offset   = absint( $args['offset'] );

		$query = "SELECT pr.*, GROUP_CONCAT(DISTINCT rp.product_id) as product_ids, GROUP_CONCAT(DISTINCT rc.category_id) as category_ids
            FROM {$wpdb->prefix}pricing_rules pr
            LEFT JOIN {$wpdb->prefix}rule_products rp ON pr.rule_id = rp.rule_id
            LEFT JOIN {$wpdb->prefix}rule_categories rc ON pr.rule_id = rc.rule_id
            GROUP BY pr.rule_id
            ORDER BY pr.sort_order ASC, pr.rule_id ASC";

		if ( $limit > 0 ) {
			$query .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		return $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query string is assembled from trusted plugin table names; the LIMIT/OFFSET fragment is prepared separately.
			$query,
			OBJECT_K
		);
	}

	/**
	 * Count pricing rules for admin pagination.
	 *
	 * @since  1.2.0
	 * @return int Total number of pricing rules.
	 */
	public function count_pricing_rules_for_admin_page() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pricing_rules" );
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
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return array();
		}

		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$product_id = $parent_id;
			}
		}

		return $this->get_cached_product_categories( $product_id );
	}

	/**
	 * Resolve the best product-specific rule for a single product and customer group.
	 *
	 * @since  1.0.0
	 * @param  int $product_id WooCommerce product ID.
	 * @param  int $group_id   Customer group ID.
	 * @return object|null Matching rule row, or null if none exists.
	 */
	private function get_product_specific_rule( $product_id, $group_id ) {
		$product_contexts          = $this->build_product_contexts( array( $product_id ) );
		$product_rules_by_match_id = $this->get_product_specific_rules_for_products( $product_contexts, $group_id );

		return $this->select_product_specific_rule( $product_contexts[ $product_id ], $product_rules_by_match_id );
	}

	/**
	 * Resolve the effective customer group ID for a user.
	 *
	 * @since  1.0.0
	 * @param  int $user_id WordPress user ID.
	 * @return int Effective customer group ID, or 0 when none applies.
	 */
	private function get_effective_group_id( $user_id ) {
		$user_id = absint( $user_id );
		if ( array_key_exists( $user_id, $this->effective_group_cache ) ) {
			return $this->effective_group_cache[ $user_id ];
		}

		$group_id = $user_id > 0 ? (int) $this->user_groups->get_user_group( $user_id ) : 0;
		if ( ! $group_id ) {
			$group_id = (int) get_option( 'wccg_default_group_id', 0 );
		}

		$this->effective_group_cache[ $user_id ] = $group_id;

		return $this->effective_group_cache[ $user_id ];
	}

	/**
	 * Build the in-request cache key for a product/group pricing lookup.
	 *
	 * @since  1.0.0
	 * @param  int $product_id WooCommerce product ID.
	 * @param  int $group_id   Customer group ID.
	 * @return string Cache key.
	 */
	private function get_rule_cache_key( $product_id, $group_id ) {
		return absint( $group_id ) . ':' . absint( $product_id );
	}

	/**
	 * Build normalized product lookup contexts for pricing-rule resolution.
	 *
	 * @since  1.1.0
	 * @param  int[] $product_ids WooCommerce product IDs.
	 * @return array<int,array> Product contexts keyed by product ID.
	 */
	private function build_product_contexts( $product_ids ) {
		$contexts = array();

		foreach ( $product_ids as $product_id ) {
			$product            = wc_get_product( $product_id );
			$category_source_id = $product_id;
			$match_ids          = array( $product_id );

			if ( $product && $product->is_type( 'variation' ) ) {
				$parent_id = $product->get_parent_id();
				if ( $parent_id ) {
					$match_ids[]        = $parent_id;
					$category_source_id = $parent_id;
				}
			}

			$contexts[ $product_id ] = array(
				'product_id'         => $product_id,
				'match_ids'          => array_values( array_unique( array_filter( array_map( 'absint', $match_ids ) ) ) ),
				'category_source_id' => absint( $category_source_id ),
			);
		}

		return $contexts;
	}

	/**
	 * Fetch active product-specific pricing rules for multiple product contexts.
	 *
	 * @since  1.1.0
	 * @param  array $product_contexts Product lookup contexts.
	 * @param  int   $group_id         Customer group ID.
	 * @return array<int,array> Matching rules keyed by matched product ID.
	 */
	private function get_product_specific_rules_for_products( $product_contexts, $group_id ) {
		global $wpdb;

		$match_ids = array();
		foreach ( $product_contexts as $context ) {
			$match_ids = array_merge( $match_ids, $context['match_ids'] );
		}

		$match_ids = array_values( array_unique( array_filter( array_map( 'absint', $match_ids ) ) ) );
		if ( empty( $match_ids ) ) {
			return array();
		}

		$pricing_rules_table = $this->db->get_table_name( 'pricing_rules' );
		$rule_products_table = $this->db->get_table_name( 'rule_products' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rules = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT pr.*, rp.product_id AS matched_product_id
             FROM ' . $pricing_rules_table . ' pr
             JOIN ' . $rule_products_table . ' rp ON pr.rule_id = rp.rule_id
            WHERE pr.group_id = %d AND rp.product_id IN (' . implode( ',', array_fill( 0, count( $match_ids ), '%d' ) ) . ') AND pr.is_active = 1
            AND (pr.start_date IS NULL OR pr.start_date <= UTC_TIMESTAMP())
            AND (pr.end_date IS NULL OR pr.end_date >= UTC_TIMESTAMP())
            ORDER BY pr.sort_order ASC, pr.rule_id ASC',
				$group_id,
				...$match_ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$rules_by_match_id = array();
		foreach ( $rules as $rule ) {
			$matched_product_id = isset( $rule->matched_product_id ) ? (int) $rule->matched_product_id : 0;
			if ( ! isset( $rules_by_match_id[ $matched_product_id ] ) ) {
				$rules_by_match_id[ $matched_product_id ] = array();
			}

			$rules_by_match_id[ $matched_product_id ][] = $rule;
		}

		return $rules_by_match_id;
	}

	/**
	 * Select the first matching product-specific rule for a product context.
	 *
	 * @since  1.1.0
	 * @param  array $product_context  Product lookup context.
	 * @param  array $rules_by_match_id Rules keyed by matched product ID.
	 * @return object|null Selected rule row, or null if none match.
	 */
	private function select_product_specific_rule( $product_context, $rules_by_match_id ) {
		foreach ( $product_context['match_ids'] as $match_id ) {
			if ( ! empty( $rules_by_match_id[ $match_id ] ) ) {
				return $rules_by_match_id[ $match_id ][0];
			}
		}

		return null;
	}

	/**
	 * Return cached product category IDs, including ancestors, for a product.
	 *
	 * @since  1.0.0
	 * @param  int $product_id WooCommerce product ID.
	 * @return int[] Category IDs.
	 */
	private function get_cached_product_categories( $product_id ) {
		$product_id = absint( $product_id );
		if ( array_key_exists( $product_id, $this->product_categories_cache ) ) {
			return $this->product_categories_cache[ $product_id ];
		}

		$terms        = wp_get_object_terms( array( $product_id ), 'product_cat', array( 'fields' => 'all_with_object_id' ) );
		$category_ids = array();

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				$category_ids[] = (int) $term->term_id;
				$ancestors      = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
				if ( ! empty( $ancestors ) ) {
					$category_ids = array_merge( $category_ids, array_map( 'intval', $ancestors ) );
				}
			}
		}

		$this->product_categories_cache[ $product_id ] = array_values( array_unique( array_filter( $category_ids ) ) );

		return $this->product_categories_cache[ $product_id ];
	}

	/**
	 * Resolve the best category-based rule for a product and customer group.
	 *
	 * @since  1.0.0
	 * @param  int $product_id WooCommerce product ID.
	 * @param  int $group_id   Customer group ID.
	 * @return object|null Matching rule row, or null if none exists.
	 */
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

	/**
	 * Fetch active category-based rules for the supplied category IDs and group.
	 *
	 * @since  1.0.0
	 * @param  int[] $category_ids Product category term IDs.
	 * @param  int   $group_id     Customer group ID.
	 * @return object[] Matching rule rows.
	 */
	private function get_category_rules( $category_ids, $group_id ) {
		global $wpdb;

		$pricing_rules_table   = $this->db->get_table_name( 'pricing_rules' );
		$rule_categories_table = $this->db->get_table_name( 'rule_categories' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT pr.*, rc.category_id
            FROM ' . $pricing_rules_table . ' pr
            JOIN ' . $rule_categories_table . ' rc ON pr.rule_id = rc.rule_id
            WHERE pr.group_id = %d AND rc.category_id IN (' . implode( ',', array_fill( 0, count( $category_ids ), '%d' ) ) . ') AND pr.is_active = 1
            AND (pr.start_date IS NULL OR pr.start_date <= UTC_TIMESTAMP())
            AND (pr.end_date IS NULL OR pr.end_date >= UTC_TIMESTAMP())
            ORDER BY pr.created_at DESC',
				$group_id,
				...$category_ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * Group category-based rules by product context.
	 *
	 * @since  1.1.0
	 * @param  array $product_contexts Product lookup contexts.
	 * @param  int   $group_id         Customer group ID.
	 * @return array<int,array> Matching rules keyed by product ID.
	 */
	private function get_category_rules_for_contexts( $product_contexts, $group_id ) {
		$category_ids_by_source = array();
		$all_category_ids       = array();

		foreach ( $product_contexts as $context ) {
			$source_id                            = $context['category_source_id'];
			$category_ids_by_source[ $source_id ] = $this->get_cached_product_categories( $source_id );
			$all_category_ids                     = array_merge( $all_category_ids, $category_ids_by_source[ $source_id ] );
		}

		$all_category_ids = array_values( array_unique( array_filter( array_map( 'absint', $all_category_ids ) ) ) );
		if ( empty( $all_category_ids ) ) {
			return array();
		}

		$rules                = $this->get_category_rules( $all_category_ids, $group_id );
		$rules_by_category_id = array();

		foreach ( $rules as $rule ) {
			$category_id = isset( $rule->category_id ) ? (int) $rule->category_id : 0;
			if ( ! isset( $rules_by_category_id[ $category_id ] ) ) {
				$rules_by_category_id[ $category_id ] = array();
			}

			$rules_by_category_id[ $category_id ][] = $rule;
		}

		$rules_by_product_id = array();
		foreach ( $product_contexts as $product_id => $context ) {
			$product_rules = array();

			foreach ( $category_ids_by_source[ $context['category_source_id'] ] as $category_id ) {
				if ( ! empty( $rules_by_category_id[ $category_id ] ) ) {
					$product_rules = array_merge( $product_rules, $rules_by_category_id[ $category_id ] );
				}
			}

			$rules_by_product_id[ $product_id ] = $product_rules;
		}

		return $rules_by_product_id;
	}

	/**
	 * Determine the highest-priority rule from a set of candidates.
	 *
	 * @since  1.0.0
	 * @param  object[] $rules Candidate rule rows.
	 * @return object|null Best rule, or null when the list is empty.
	 */
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

	/**
	 * Compare two discount rules and return their priority ordering.
	 *
	 * @since  1.0.0
	 * @param  object $rule1 First rule row.
	 * @param  object $rule2 Second rule row.
	 * @return int Positive when $rule1 wins, negative when $rule2 wins.
	 */
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
