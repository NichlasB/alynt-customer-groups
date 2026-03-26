<?php
/**
 * Database maintenance operations: cleanup and schema upgrades.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes orphaned data, prunes old error logs, and runs version-specific schema migrations.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Maintenance_Repository {
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Maintenance_Repository|null
	 */
	private static $instance = null;

	/**
	 * Database facade.
	 *
	 * @var WCCG_Database
	 */
	private $db;

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Maintenance_Repository
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
		$this->db = WCCG_Database::instance();
	}

	/**
	 * Delete user-group assignments, rule-product rows, and rule-category rows whose
	 * parent records (users, posts, terms) no longer exist.
	 *
	 * @since  1.0.0
	 * @return bool True on success, false on transaction failure.
	 */
	public function cleanup_orphaned_data() {
		global $wpdb;

		return $this->db->transaction(
			function () use ( $wpdb ) {
				$cleanup_operations = array(
					'user_assignments'             => array(
						'table' => $this->db->get_table_name( 'user_groups' ),
						'query' => "DELETE ug FROM {$this->db->get_table_name('user_groups')} ug
                    LEFT JOIN {$wpdb->users} u ON ug.user_id = u.ID
                    WHERE u.ID IS NULL",
					),
					'orphaned_rules'               => array(
						'table' => $this->db->get_table_name( 'pricing_rules' ),
						'query' => "DELETE pr FROM {$this->db->get_table_name('pricing_rules')} pr
                    LEFT JOIN {$this->db->get_table_name('groups')} g ON pr.group_id = g.group_id
                    WHERE g.group_id IS NULL",
					),
					'product_rules'                => array(
						'table' => $this->db->get_table_name( 'rule_products' ),
						'query' => "DELETE rp FROM {$this->db->get_table_name('rule_products')} rp
                    LEFT JOIN {$wpdb->posts} p ON rp.product_id = p.ID
                    WHERE p.ID IS NULL",
					),
					'orphaned_product_rule_links'  => array(
						'table' => $this->db->get_table_name( 'rule_products' ),
						'query' => "DELETE rp FROM {$this->db->get_table_name('rule_products')} rp
                    LEFT JOIN {$this->db->get_table_name('pricing_rules')} pr ON rp.rule_id = pr.rule_id
                    WHERE pr.rule_id IS NULL",
					),
					'category_rules'               => array(
						'table' => $this->db->get_table_name( 'rule_categories' ),
						'query' => "DELETE rc FROM {$this->db->get_table_name('rule_categories')} rc
                    LEFT JOIN {$wpdb->term_taxonomy} tt ON rc.category_id = tt.term_id
                    WHERE tt.term_id IS NULL",
					),
					'orphaned_category_rule_links' => array(
						'table' => $this->db->get_table_name( 'rule_categories' ),
						'query' => "DELETE rc FROM {$this->db->get_table_name('rule_categories')} rc
                    LEFT JOIN {$this->db->get_table_name('pricing_rules')} pr ON rc.rule_id = pr.rule_id
                    WHERE pr.rule_id IS NULL",
					),
				);

				foreach ( $cleanup_operations as $operation => $details ) {
					$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $details['table'] ) ) ) === $details['table'];
					if ( ! $table_exists ) {
						continue;
					}

					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Cleanup queries are constructed from trusted plugin table names and core $wpdb table names only.
					$result = $wpdb->query( $details['query'] );
					if ( $result === false ) {
						throw new Exception( sprintf( 'Failed to cleanup %s', esc_html( $operation ) ) );
					}
				}

				return true;
			}
		);
	}

	/**
	 * Remove any group assignment row for a user that was just deleted.
	 *
	 * @since  1.2.0
	 * @param  int $user_id Deleted WordPress user ID.
	 * @return bool True on success, false on failure.
	 */
	public function cleanup_deleted_user_assignment( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		$result = $wpdb->delete(
			$this->db->get_table_name( 'user_groups' ),
			array( 'user_id' => $user_id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Remove product-rule links for a deleted product or variation.
	 *
	 * @since  1.2.0
	 * @param  int $product_id Deleted product or variation ID.
	 * @return bool True on success, false on failure.
	 */
	public function cleanup_deleted_product_rule_links( $product_id ) {
		global $wpdb;

		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return false;
		}

		$result = $wpdb->delete(
			$this->db->get_table_name( 'rule_products' ),
			array( 'product_id' => $product_id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Remove category-rule links for a deleted product category and refresh affected caches.
	 *
	 * @since  1.2.0
	 * @param  int $category_id Deleted product category term ID.
	 * @return bool True on success, false on failure.
	 */
	public function cleanup_deleted_category_rule_links( $category_id ) {
		global $wpdb;

		$category_id = absint( $category_id );
		if ( ! $category_id ) {
			return false;
		}

		$rule_categories_table = $this->db->get_table_name( 'rule_categories' );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rule_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT rule_id FROM ' . $rule_categories_table . ' WHERE category_id = %d',
				$category_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->delete(
			$this->db->get_table_name( 'rule_categories' ),
			array( 'category_id' => $category_id ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		if ( ! empty( $rule_ids ) && class_exists( 'WCCG_Core' ) ) {
			WCCG_Core::instance()->refresh_price_caches_for_rule_ids( $rule_ids );
		}

		return true;
	}

	/**
	 * Delete error log entries older than 30 days (non-critical) or 90 days (critical).
	 *
	 * @since  1.0.0
	 * @return bool True on success, false if the log table does not exist.
	 */
	public function cleanup_old_logs() {
		global $wpdb;

		return $this->db->transaction(
			function () use ( $wpdb ) {
				$table_name   = $this->db->get_table_name( 'error_log' );
				$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name;

				if ( ! $table_exists ) {
					return false;
				}

				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query(
					'DELETE FROM ' . $table_name . '
                WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND severity != \'critical\''
				);

				$wpdb->query(
					'DELETE FROM ' . $table_name . '
                WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)
                AND severity = \'critical\''
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

				return true;
			}
		);
	}

	/**
	 * Run version-specific schema migration routines.
	 *
	 * @since  1.0.0
	 * @param  string $installed_version The previously installed plugin version string.
	 * @return bool True if all applicable upgrades succeeded, false if any failed.
	 */
	public function run_upgrades( $installed_version ) {
		if ( version_compare( $installed_version, '1.1.0', '<' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			WCCG_Activator::activate();
		}

		return true;
	}
}
