<?php
/**
 * Write operations for pricing rules.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and updates pricing rules and their product/category associations.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Pricing_Rule_Write_Service {
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Pricing_Rule_Write_Service|null
	 */
	private static $instance = null;

	/**
	 * Database facade.
	 *
	 * @var WCCG_Database
	 */
	private $db;

	/**
	 * Utility helper facade.
	 *
	 * @var WCCG_Utilities
	 */
	private $utils;

	/**
	 * Last pricing rule ID inserted during the current request.
	 *
	 * @var int
	 */
	private $last_saved_rule_id = 0;

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Pricing_Rule_Write_Service
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
		$this->db    = WCCG_Database::instance();
		$this->utils = WCCG_Utilities::instance();
	}

	/**
	 * Convert a local datetime string (from an HTML datetime-local input) to UTC.
	 *
	 * @since  1.1.0
	 * @param  string $datetime_local Datetime string in site timezone (e.g. '2026-03-01T14:00').
	 * @return string|WP_Error UTC datetime string formatted as 'Y-m-d H:i:s' or WP_Error on invalid input.
	 */
	public function convert_to_utc( $datetime_local ) {
		$datetime_local = sanitize_text_field( (string) $datetime_local );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $datetime_local ) ) {
			return new WP_Error( 'invalid_datetime', __( 'Enter a valid date and time.', 'alynt-customer-groups' ) );
		}

		try {
			$datetime_formatted = str_replace( 'T', ' ', $datetime_local ) . ':00';
			$dt                 = new DateTime( $datetime_formatted, wp_timezone() );
			$dt->setTimezone( new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return new WP_Error( 'invalid_datetime', __( 'Enter a valid date and time.', 'alynt-customer-groups' ) );
		}
	}

	/**
	 * Update an existing pricing rule's core fields and replace its product/category associations.
	 *
	 * @since  1.0.0
	 * @param  int    $rule_id       The ID of the rule to update.
	 * @param  int    $group_id      Customer group ID.
	 * @param  string $discount_type 'percentage' or 'fixed'.
	 * @param  float  $discount_value Discount amount.
	 * @param  int[]  $product_ids   Product IDs to associate with this rule.
	 * @param  int[]  $category_ids  Category IDs to associate with this rule.
	 * @return bool True on success, false on transaction failure.
	 */
	public function update_pricing_rule( $rule_id, $group_id, $discount_type, $discount_value, $product_ids, $category_ids ) {
		$result = $this->db->transaction(
			function () use ( $rule_id, $group_id, $discount_type, $discount_value, $product_ids, $category_ids ) {
				global $wpdb;
				$product_ids  = $this->get_valid_product_ids( $product_ids );
				$category_ids = $this->get_valid_category_ids( $category_ids );

				$result = $wpdb->update(
					$wpdb->prefix . 'pricing_rules',
					array(
						'group_id'       => $group_id,
						'discount_type'  => $discount_type,
						'discount_value' => $discount_value,
					),
					array( 'rule_id' => $rule_id ),
					array( '%d', '%s', '%f' ),
					array( '%d' )
				);

				if ( $result === false ) {
					throw new Exception( 'Failed to update pricing rule' );
				}

				if ( 0 === $result && ! $this->db->pricing_rule_exists( $rule_id ) ) {
					throw new Exception( 'Pricing rule no longer exists' );
				}

				$wpdb->delete( $wpdb->prefix . 'rule_products', array( 'rule_id' => $rule_id ), array( '%d' ) );
				$wpdb->delete( $wpdb->prefix . 'rule_categories', array( 'rule_id' => $rule_id ), array( '%d' ) );

				foreach ( $product_ids as $product_id ) {
					$result = $wpdb->insert(
						$wpdb->prefix . 'rule_products',
						array(
							'rule_id'    => $rule_id,
							'product_id' => $product_id,
						),
						array( '%d', '%d' )
					);

					if ( $result === false ) {
						throw new Exception( 'Failed to insert product association' );
					}
				}

				foreach ( $category_ids as $category_id ) {
					$result = $wpdb->insert(
						$wpdb->prefix . 'rule_categories',
						array(
							'rule_id'     => $rule_id,
							'category_id' => $category_id,
						),
						array( '%d', '%d' )
					);

					if ( $result === false ) {
						throw new Exception( 'Failed to insert category association' );
					}
				}

				return true;
			}
		);

		if ( $result ) {
			$this->refresh_rule_price_caches( array( $rule_id ) );
		}

		return $result;
	}

	/**
	 * Insert a new pricing rule and associate it with products and/or categories.
	 *
	 * @since  1.0.0
	 * @param  int         $group_id      Customer group ID.
	 * @param  string      $discount_type 'percentage' or 'fixed'.
	 * @param  float       $discount_value Discount amount.
	 * @param  int[]       $product_ids   Product IDs to associate with this rule.
	 * @param  int[]       $category_ids  Category IDs to associate with this rule.
	 * @param  string|null $start_date    UTC start datetime, or null for no start constraint.
	 * @param  string|null $end_date      UTC end datetime, or null for no end constraint.
	 * @return bool True on success, false on transaction failure.
	 */
	public function save_pricing_rule( $group_id, $discount_type, $discount_value, $product_ids, $category_ids, $start_date = null, $end_date = null ) {
		$result = $this->db->transaction(
			function () use ( $group_id, $discount_type, $discount_value, $product_ids, $category_ids, $start_date, $end_date ) {
				global $wpdb;
				$product_ids  = $this->get_valid_product_ids( $product_ids );
				$category_ids = $this->get_valid_category_ids( $category_ids );

				$insert_data               = array(
					'group_id'       => $group_id,
					'discount_type'  => $discount_type,
					'discount_value' => $discount_value,
					'is_active'      => 1,
				);
				$insert_format             = array( '%d', '%s', '%f', '%d' );
				$max_sort_order            = $wpdb->get_var( "SELECT MAX(sort_order) FROM {$wpdb->prefix}pricing_rules" );
				$insert_data['sort_order'] = $max_sort_order ? $max_sort_order + 1 : 1;
				$insert_format[]           = '%d';

				if ( $start_date !== null ) {
					$insert_data['start_date'] = $start_date;
					$insert_format[]           = '%s';
				}

				if ( $end_date !== null ) {
					$insert_data['end_date'] = $end_date;
					$insert_format[]         = '%s';
				}

				$result = $wpdb->insert( $wpdb->prefix . 'pricing_rules', $insert_data, $insert_format );
				if ( $result === false ) {
					throw new Exception( 'Failed to insert pricing rule' );
				}

				$this->last_saved_rule_id = (int) $wpdb->insert_id;
				$rule_id                  = $this->last_saved_rule_id;
				foreach ( $product_ids as $product_id ) {
					$result = $wpdb->insert(
						$wpdb->prefix . 'rule_products',
						array(
							'rule_id'    => $rule_id,
							'product_id' => $product_id,
						),
						array( '%d', '%d' )
					);
					if ( $result === false ) {
						throw new Exception( 'Failed to insert product association' );
					}
				}

				foreach ( $category_ids as $category_id ) {
					$result = $wpdb->insert(
						$wpdb->prefix . 'rule_categories',
						array(
							'rule_id'     => $rule_id,
							'category_id' => $category_id,
						),
						array( '%d', '%d' )
					);
					if ( $result === false ) {
						throw new Exception( 'Failed to insert category association' );
					}
				}

				return true;
			}
		);

		if ( $result && ! empty( $this->last_saved_rule_id ) ) {
			$this->refresh_rule_price_caches( array( (int) $this->last_saved_rule_id ) );
		}

		$this->last_saved_rule_id = 0;

		return $result;
	}

	/**
	 * Delete a pricing rule and its product/category associations.
	 *
	 * @since  1.0.0
	 * @param  int $rule_id Pricing rule ID.
	 * @return bool True on success, false on transaction failure.
	 */
	public function delete_pricing_rule( $rule_id ) {
		$result = $this->db->transaction(
			function () use ( $rule_id ) {
				global $wpdb;

				if ( ! $this->db->pricing_rule_exists( $rule_id ) ) {
					return 0;
				}

				$wpdb->delete( $wpdb->prefix . 'rule_products', array( 'rule_id' => $rule_id ), array( '%d' ) );
				$wpdb->delete( $wpdb->prefix . 'rule_categories', array( 'rule_id' => $rule_id ), array( '%d' ) );

				return $wpdb->delete( $wpdb->prefix . 'pricing_rules', array( 'rule_id' => $rule_id ), array( '%d' ) );
			}
		);

		if ( false !== $result ) {
			$this->refresh_rule_price_caches( array( $rule_id ) );
		}

		return $result;
	}

	/**
	 * Update one pricing rule active status.
	 *
	 * @since  1.0.0
	 * @param  int $rule_id Pricing rule ID.
	 * @param  int $status  Active flag, 0 or 1.
	 * @return bool True on success, false on failure.
	 */
	public function update_pricing_rule_status( $rule_id, $status ) {
		global $wpdb;

		$result = $wpdb->update(
			$wpdb->prefix . 'pricing_rules',
			array( 'is_active' => $status ),
			array( 'rule_id' => $rule_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			$this->refresh_rule_price_caches( array( $rule_id ) );
		}

		return false !== $result;
	}

	/**
	 * Update all pricing rules active status in bulk.
	 *
	 * @since  1.0.0
	 * @param  int $status Active flag, 0 or 1.
	 * @return int|false Number of affected rows, or false on failure.
	 */
	public function bulk_update_pricing_rule_status( $status ) {
		global $wpdb;

		$rule_ids = $wpdb->get_col( "SELECT rule_id FROM {$wpdb->prefix}pricing_rules" );
		$result   = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}pricing_rules SET is_active = %d",
				$status
			)
		);

		if ( false !== $result && ! empty( $rule_ids ) ) {
			$this->refresh_rule_price_caches( $rule_ids );
		}

		return $result;
	}

	/**
	 * Update one pricing rule schedule.
	 *
	 * @since  1.0.0
	 * @param  int         $rule_id    Pricing rule ID.
	 * @param  string|null $start_date UTC start datetime, or null.
	 * @param  string|null $end_date   UTC end datetime, or null.
	 * @return bool True on success, false on failure.
	 */
	public function update_pricing_rule_schedule( $rule_id, $start_date, $end_date ) {
		global $wpdb;

		$result = $wpdb->update(
			$wpdb->prefix . 'pricing_rules',
			array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
			),
			array( 'rule_id' => $rule_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( 0 === $result && ! $this->db->pricing_rule_exists( $rule_id ) ) {
			return false;
		}

		if ( false !== $result ) {
			$this->refresh_rule_price_caches( array( $rule_id ) );
		}

		return false !== $result;
	}

	/**
	 * Delete all pricing rules and their related product/category rows.
	 *
	 * @since  1.0.0
	 * @return int|false Number of deleted rules, 0 when nothing exists, or false on failure.
	 */
	public function delete_all_pricing_rules() {
		global $wpdb;

		$rule_ids = $wpdb->get_col( "SELECT rule_id FROM {$wpdb->prefix}pricing_rules" );
		$result   = $this->db->transaction(
			function () {
				global $wpdb;

				$rule_ids = $wpdb->get_col( "SELECT rule_id FROM {$wpdb->prefix}pricing_rules" );
				if ( empty( $rule_ids ) ) {
					return 0;
				}

				$product_delete_result = $wpdb->query( "DELETE FROM {$wpdb->prefix}rule_products" );
				if ( false === $product_delete_result ) {
					throw new Exception( 'Failed to delete pricing rule product associations' );
				}

				$category_delete_result = $wpdb->query( "DELETE FROM {$wpdb->prefix}rule_categories" );
				if ( false === $category_delete_result ) {
					throw new Exception( 'Failed to delete pricing rule category associations' );
				}

				$rule_delete_result = $wpdb->query( "DELETE FROM {$wpdb->prefix}pricing_rules" );
				if ( false === $rule_delete_result ) {
					throw new Exception( 'Failed to delete pricing rules' );
				}

				return $rule_delete_result;
			}
		);

		if ( false !== $result && ! empty( $rule_ids ) ) {
			$this->refresh_rule_price_caches( $rule_ids );
		}

		return $result;
	}

	/**
	 * Persist a submitted pricing rule sort order.
	 *
	 * @since  1.0.0
	 * @param  int[] $order Ordered rule IDs.
	 * @return bool True on success, false on failure.
	 */
	public function reorder_pricing_rules( $order ) {
		global $wpdb;

		$sort_order = 1;
		foreach ( $order as $rule_id ) {
			$result = $wpdb->update(
				$wpdb->prefix . 'pricing_rules',
				array( 'sort_order' => $sort_order ),
				array( 'rule_id' => $rule_id ),
				array( '%d' ),
				array( '%d' )
			);
			if ( $result === false ) {
				return false;
			}

			++$sort_order;
		}

		return true;
	}

	/**
	 * Return a list of human-readable conflict messages for duplicate product/category rules.
	 *
	 * @since  1.0.0
	 * @param  int $limit Maximum number of conflicts to return.
	 * @return string[] Array of conflict description strings.
	 */
	public function get_rule_conflicts( $limit = 10 ) {
		global $wpdb;

		$conflicts         = array();
		$limit             = max( 1, absint( $limit ) );
		$product_conflicts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.product_id, pr.group_id, COUNT(*) as rule_count, g.group_name
            FROM {$wpdb->prefix}rule_products p
            JOIN {$wpdb->prefix}pricing_rules pr ON p.rule_id = pr.rule_id
            JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
            GROUP BY p.product_id, pr.group_id
            HAVING rule_count > 1
            LIMIT %d",
				$limit
			)
		);

		foreach ( $product_conflicts as $conflict ) {
			$product = wc_get_product( $conflict->product_id );
			if ( $product ) {
				/* translators: 1: Product name, 2: Customer group name. */
				$conflicts[] = sprintf(
					/* translators: 1: Product name, 2: Customer group name. */
					__( 'Product "%1$s" has multiple rules for group "%2$s"', 'alynt-customer-groups' ),
					$product->get_name(),
					$conflict->group_name
				);
			}
		}

		$category_conflicts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rc.category_id, pr.group_id, COUNT(DISTINCT pr.rule_id) as rule_count, g.group_name, t.name as category_name
            FROM {$wpdb->prefix}rule_categories rc
            JOIN {$wpdb->prefix}pricing_rules pr ON rc.rule_id = pr.rule_id
            JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
            JOIN {$wpdb->prefix}terms t ON rc.category_id = t.term_id
            GROUP BY rc.category_id, pr.group_id
            HAVING rule_count > 1
            LIMIT %d",
				$limit
			)
		);

		foreach ( $category_conflicts as $conflict ) {
			/* translators: 1: Category name, 2: Customer group name. */
			$conflicts[] = sprintf(
				/* translators: 1: Category name, 2: Customer group name. */
				__( 'Category "%1$s" has multiple rules for group "%2$s"', 'alynt-customer-groups' ),
				$conflict->category_name,
				$conflict->group_name
			);
		}

		return $conflicts;
	}

	/**
	 * Validate that the supplied product IDs exist and are eligible for pricing rules.
	 *
	 * @since  1.0.0
	 * @param  int[] $product_ids Submitted product IDs.
	 * @return int[] Validated product IDs.
	 * @throws Exception When any submitted product ID is invalid.
	 */
	private function get_valid_product_ids( $product_ids ) {
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );
		if ( empty( $product_ids ) ) {
			return array();
		}

		$valid_product_ids = get_posts(
			array(
				'post_type'              => array( 'product', 'product_variation' ),
				'post_status'            => 'any',
				'posts_per_page'         => count( $product_ids ),
				'orderby'                => 'post__in',
				'include'                => $product_ids,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$valid_product_ids = array_values( array_map( 'absint', $valid_product_ids ) );
		if ( count( $valid_product_ids ) !== count( $product_ids ) ) {
			throw new Exception( 'Invalid pricing rule product selection' );
		}

		return $product_ids;
	}

	/**
	 * Validate that the supplied category IDs exist and are eligible for pricing rules.
	 *
	 * @since  1.0.0
	 * @param  int[] $category_ids Submitted category IDs.
	 * @return int[] Validated category IDs.
	 * @throws Exception When any submitted category ID is invalid.
	 */
	private function get_valid_category_ids( $category_ids ) {
		$category_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $category_ids ) ) ) );
		if ( empty( $category_ids ) ) {
			return array();
		}

		$valid_category_ids = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'include'    => $category_ids,
				'fields'     => 'ids',
			)
		);

		if ( is_wp_error( $valid_category_ids ) ) {
			throw new Exception( 'Invalid pricing rule category selection' );
		}

		$valid_category_ids = array_values( array_map( 'absint', $valid_category_ids ) );
		if ( count( $valid_category_ids ) !== count( $category_ids ) ) {
			throw new Exception( 'Invalid pricing rule category selection' );
		}

		return $category_ids;
	}

	/**
	 * Refresh product price caches affected by the supplied rule IDs.
	 *
	 * @since  1.0.0
	 * @param  int[] $rule_ids Pricing rule IDs.
	 * @return void
	 */
	private function refresh_rule_price_caches( $rule_ids ) {
		$rule_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $rule_ids ) ) ) );
		if ( empty( $rule_ids ) || ! class_exists( 'WCCG_Core' ) ) {
			return;
		}

		WCCG_Core::instance()->refresh_price_caches_for_rule_ids( $rule_ids );
	}
}
