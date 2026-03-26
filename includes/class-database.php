<?php
/**
 * Database facade — provides a unified API over all repository classes.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central database access object. Lazily instantiates repository classes and wraps
 * transaction and batch helpers for use across the plugin.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Database {
	private static $instance = null;
	private $tables;
	private $user_groups;
	private $pricing_rules;
	private $maintenance;

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Database
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		global $wpdb;

		$this->tables = array(
			'error_log'       => $wpdb->prefix . 'wccg_error_log',
			'groups'          => $wpdb->prefix . 'customer_groups',
			'user_groups'     => $wpdb->prefix . 'user_groups',
			'pricing_rules'   => $wpdb->prefix . 'pricing_rules',
			'rule_products'   => $wpdb->prefix . 'rule_products',
			'rule_categories' => $wpdb->prefix . 'rule_categories',
		);
	}

	private function user_groups() {
		if ( ! $this->user_groups ) {
			$this->user_groups = WCCG_User_Group_Repository::instance();
		}

		return $this->user_groups;
	}

	private function pricing_rules() {
		if ( ! $this->pricing_rules ) {
			$this->pricing_rules = WCCG_Pricing_Rule_Repository::instance();
		}

		return $this->pricing_rules;
	}

	private function maintenance() {
		if ( ! $this->maintenance ) {
			$this->maintenance = WCCG_Maintenance_Repository::instance();
		}

		return $this->maintenance;
	}

	/**
	 * Return the full prefixed table name for a given key.
	 *
	 * @since  1.0.0
	 * @param  string $key Table key (e.g. 'groups', 'pricing_rules').
	 * @return string Full table name, or empty string if key is not registered.
	 */
	public function get_table_name( $key ) {
		return isset( $this->tables[ $key ] ) ? $this->tables[ $key ] : '';
	}

	/**
	 * Execute a callback inside a database transaction, rolling back on any exception.
	 *
	 * @since  1.0.0
	 * @param  callable $callback Function to execute. Should return a non-false value on success.
	 * @return mixed Return value of $callback, or false if the transaction was rolled back.
	 */
	public function transaction( $callback ) {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );

		try {
			$result = $callback();

			if ( $result === false ) {
				throw new Exception( 'Transaction failed' );
			}

			if ( $wpdb->last_error ) {
				throw new Exception( $wpdb->last_error );
			}

			$wpdb->query( 'COMMIT' );
			return $result;
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				WCCG_Logger::instance()->log_error(
					'Transaction failed: ' . $e->getMessage(),
					array(
						'trace'      => $e->getTraceAsString(),
						'last_query' => $wpdb->last_query,
					),
					'critical'
				);
			}

			return false;
		}
	}

	/**
	 * Process a large set of items in batches within a single transaction.
	 *
	 * @since  1.0.0
	 * @param  callable $callback  Called once per batch with that batch's items as the argument.
	 * @param  array    $items      Full list of items to process.
	 * @param  int      $batch_size Number of items per batch. Default 1000.
	 * @return bool True on success, false if any batch fails.
	 */
	public function batch_operation( $callback, $items, $batch_size = 1000 ) {
		return $this->transaction(
			function () use ( $callback, $items, $batch_size ) {
				foreach ( array_chunk( $items, $batch_size ) as $batch ) {
					$result = $callback( $batch );
					if ( $result === false ) {
						throw new Exception( 'Batch operation failed' );
					}
				}

				return true;
			}
		);
	}

	/**
	 * Get the group ID assigned to a user.
	 *
	 * @since  1.0.0
	 * @param  int $user_id WordPress user ID.
	 * @return string|null Group ID as a string, or null if the user has no group.
	 */
	public function get_user_group( $user_id ) {
		return $this->user_groups()->get_user_group( $user_id );
	}

	/**
	 * Get the group name for a user.
	 *
	 * @since  1.0.0
	 * @param  int $user_id WordPress user ID.
	 * @return string|null Group name, or null if the user has no group.
	 */
	public function get_user_group_name( $user_id ) {
		return $this->user_groups()->get_user_group_name( $user_id );
	}

	/**
	 * Get all user IDs assigned to a group.
	 *
	 * @since  1.0.0
	 * @param  int $group_id Customer group ID.
	 * @return array Array of user ID strings.
	 */
	public function get_users_in_group( $group_id ) {
		return $this->user_groups()->get_users_in_group( $group_id );
	}

	/**
	 * Fetch all customer groups ordered by name.
	 *
	 * @since  1.0.0
	 * @return object[] Array of group rows.
	 */
	public function get_groups() {
		return $this->user_groups()->get_groups();
	}

	/**
	 * Assign multiple users to a group, replacing any existing assignments.
	 *
	 * @since  1.0.0
	 * @param  int[] $user_ids Array of WordPress user IDs.
	 * @param  int   $group_id Target customer group ID.
	 * @return bool True on success, false on failure.
	 */
	public function bulk_assign_user_groups( $user_ids, $group_id ) {
		return $this->user_groups()->bulk_assign_user_groups( $user_ids, $group_id );
	}

	/**
	 * Remove group assignments for the provided users.
	 *
	 * @since  1.0.0
	 * @param  int[] $user_ids Array of WordPress user IDs.
	 * @return int|false Number of deleted rows, or false on failure.
	 */
	public function bulk_unassign_user_groups( $user_ids ) {
		return $this->user_groups()->bulk_unassign_user_groups( $user_ids );
	}

	/**
	 * Get the applicable pricing rule for a product and user.
	 *
	 * @since  1.0.0
	 * @param  int $product_id WooCommerce product or variation ID.
	 * @param  int $user_id    WordPress user ID (0 for guest).
	 * @return object|null stdClass rule row, or null if no rule applies.
	 */
	public function get_pricing_rule_for_product( $product_id, $user_id ) {
		return $this->pricing_rules()->get_pricing_rule_for_product( $product_id, $user_id );
	}

	/**
	 * Fetch pricing rules for the Pricing Rules admin page.
	 *
	 * @since  1.0.0
	 * @return object[] Array of rules keyed by rule ID.
	 */
	public function get_pricing_rules_for_admin_page() {
		return $this->pricing_rules()->get_pricing_rules_for_admin_page();
	}

	/**
	 * Fetch all pricing rules applied directly to a product.
	 *
	 * @since  1.0.0
	 * @param  int $product_id Product post ID.
	 * @return object[] Array of matching rule rows.
	 */
	public function get_product_pricing_rules( $product_id ) {
		return $this->pricing_rules()->get_product_pricing_rules( $product_id );
	}

	/**
	 * Fetch all pricing rules applied to a product category.
	 *
	 * @since  1.0.0
	 * @param  int $category_id Product category term ID.
	 * @return object[] Array of matching rule rows.
	 */
	public function get_category_pricing_rules( $category_id ) {
		return $this->pricing_rules()->get_category_pricing_rules( $category_id );
	}

	/**
	 * Determine whether a pricing rule exists.
	 *
	 * @since  1.0.0
	 * @param  int $rule_id Pricing rule ID.
	 * @return bool True when the rule exists.
	 */
	public function pricing_rule_exists( $rule_id ) {
		return $this->pricing_rules()->pricing_rule_exists( $rule_id );
	}

	/**
	 * Fetch the active status for a pricing rule.
	 *
	 * @since  1.0.0
	 * @param  int $rule_id Pricing rule ID.
	 * @return int|null Status value, or null when the rule is missing.
	 */
	public function get_pricing_rule_status( $rule_id ) {
		return $this->pricing_rules()->get_pricing_rule_status( $rule_id );
	}

	/**
	 * Fetch a pricing rule row by ID.
	 *
	 * @since  1.0.0
	 * @param  int $rule_id Pricing rule ID.
	 * @return object|null Rule row, or null when not found.
	 */
	public function get_pricing_rule( $rule_id ) {
		return $this->pricing_rules()->get_pricing_rule( $rule_id );
	}

	/**
	 * Fetch related product IDs for a pricing rule.
	 *
	 * @since  1.0.0
	 * @param  int $rule_id Pricing rule ID.
	 * @return array Array of product IDs.
	 */
	public function get_pricing_rule_product_ids( $rule_id ) {
		return $this->pricing_rules()->get_pricing_rule_product_ids( $rule_id );
	}

	/**
	 * Fetch related category IDs for a pricing rule.
	 *
	 * @since  1.0.0
	 * @param  int $rule_id Pricing rule ID.
	 * @return array Array of category IDs.
	 */
	public function get_pricing_rule_category_ids( $rule_id ) {
		return $this->pricing_rules()->get_pricing_rule_category_ids( $rule_id );
	}

	/**
	 * Get all product category IDs (including ancestors) for a product.
	 *
	 * @since  1.0.0
	 * @param  int $product_id WooCommerce product ID.
	 * @return int[] Unique array of category term IDs.
	 */
	public function get_all_product_categories( $product_id ) {
		return $this->pricing_rules()->get_all_product_categories( $product_id );
	}

	/**
	 * Delete all pricing rules and their product/category associations for a group.
	 *
	 * @since  1.0.0
	 * @param  int $group_id Customer group ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_group_pricing_rules( $group_id ) {
		return $this->pricing_rules()->delete_group_pricing_rules( $group_id );
	}

	/**
	 * Remove orphaned rule-product and rule-category rows for deleted posts/terms.
	 *
	 * @since  1.0.0
	 * @return bool True on success, false on failure.
	 */
	public function cleanup_orphaned_data() {
		return $this->maintenance()->cleanup_orphaned_data();
	}

	/**
	 * Delete error log entries older than 30 days (non-critical) or 90 days (critical).
	 *
	 * @since  1.0.0
	 * @return bool True on success, false if the log table does not exist.
	 */
	public function cleanup_old_logs() {
		return $this->maintenance()->cleanup_old_logs();
	}

	/**
	 * Remove user-group assignments for users that no longer exist.
	 *
	 * @since  1.0.0
	 * @return bool True on success, false on failure.
	 */
	public function cleanup_orphaned_group_assignments() {
		return $this->user_groups()->cleanup_orphaned_group_assignments();
	}

	/**
	 * Run version-specific database upgrade routines.
	 *
	 * @since  1.0.0
	 * @param  string $installed_version The previously installed plugin version string.
	 * @return bool True if all upgrades succeeded, false if any failed.
	 */
	public function run_upgrades( $installed_version ) {
		return $this->maintenance()->run_upgrades( $installed_version );
	}
}
