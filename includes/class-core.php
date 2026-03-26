<?php
/**
 * Core plugin functionality: cron verification, expiration checks, and cleanup.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages scheduled tasks: verifies cron events are registered, auto-deactivates expired
 * pricing rules, clears WooCommerce price caches, and runs database cleanup routines.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Core {
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Core|null
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
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Core
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize core dependencies and register plugin hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function __construct() {
		$this->db    = WCCG_Database::instance();
		$this->utils = WCCG_Utilities::instance();
		$this->init_hooks();
	}

	/**
	 * Register all cron and cleanup hooks used by the core service.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function init_hooks() {
		/**
		 * Fires when the plugin's daily maintenance cron event runs.
		 *
		 * @since 1.0.0
		 */
		add_action( 'wccg_cleanup_cron', array( $this, 'run_cleanup_tasks' ) );

		/**
		 * Fires every five minutes to disable expired pricing rules and refresh price caches.
		 *
		 * @since 1.1.0
		 */
		add_action( 'wccg_check_expired_rules', array( $this, 'deactivate_expired_rules' ) );

		/**
		 * Fires after WordPress is fully loaded so missed expiration checks can be recovered
		 * even when WP-Cron is disabled or delayed.
		 *
		 * @since 1.2.0
		 */
		add_action( 'wp_loaded', array( $this, 'maybe_run_expiration_fallback' ) );

		/**
		 * Fires during every admin request so the daily cleanup cron can be rescheduled if needed.
		 *
		 * @since 1.0.0
		 */
		add_action( 'admin_init', array( $this, 'verify_cleanup_schedule' ) );

		/**
		 * Fires during every admin request so the 5-minute expiration cron can be rescheduled if needed.
		 *
		 * @since 1.1.0
		 */
		add_action( 'admin_init', array( $this, 'verify_expiration_schedule' ) );

		/**
		 * Fires during admin requests so missed maintenance can recover without waiting for cron.
		 *
		 * @since 1.2.0
		 */
		add_action( 'admin_init', array( $this, 'maybe_run_cleanup_fallback' ) );

		/**
		 * Fires after a user is permanently deleted so any group assignment row can be removed immediately.
		 *
		 * @since 1.2.0
		 */
		add_action( 'deleted_user', array( $this, 'handle_deleted_user' ) );

		/**
		 * Fires before a post is deleted so product-specific rule links can be removed immediately.
		 *
		 * @since 1.2.0
		 */
		add_action( 'before_delete_post', array( $this, 'handle_deleted_post' ) );

		/**
		 * Fires before a term is deleted so product-category rule links can be removed immediately.
		 *
		 * @since 1.2.0
		 */
		add_action( 'delete_term', array( $this, 'handle_deleted_term' ), 10, 3 );
	}

	/**
	 * Ensure the daily cleanup cron event is scheduled; reschedule it if missing.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function verify_cleanup_schedule() {
		if ( ! wp_next_scheduled( 'wccg_cleanup_cron' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 2am' ), 'daily', 'wccg_cleanup_cron' );
		}
	}

	/**
	 * Ensure the 5-minute expiration-check cron event is scheduled; reschedule it if missing.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public function verify_expiration_schedule() {
		if ( ! wp_next_scheduled( 'wccg_check_expired_rules' ) ) {
			wp_schedule_event( time(), 'wccg_five_minutes', 'wccg_check_expired_rules' );
		}
	}

	/**
	 * Run an on-request expiration check if cron appears delayed.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public function maybe_run_expiration_fallback() {
		if ( wp_doing_cron() || $this->is_recent_run( 'wccg_last_expiration_check', 10 * MINUTE_IN_SECONDS ) ) {
			return;
		}

		$this->run_with_lock(
			'wccg_expiration_fallback_lock',
			MINUTE_IN_SECONDS,
			array( $this, 'deactivate_expired_rules' )
		);
	}

	/**
	 * Run daily maintenance from an admin request if cron appears delayed.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public function maybe_run_cleanup_fallback() {
		if ( wp_doing_cron() || $this->is_recent_run( 'wccg_last_cleanup', DAY_IN_SECONDS ) ) {
			return;
		}

		$this->run_with_lock(
			'wccg_cleanup_fallback_lock',
			5 * MINUTE_IN_SECONDS,
			array( $this, 'run_cleanup_tasks' )
		);
	}

	/**
	 * Deactivate pricing rules whose end_date has passed and clear WooCommerce price caches
	 * when any rule changes state.
	 *
	 * The 7-minute look-back window for newly-active rules covers the 5-minute cron interval
	 * plus a 2-minute buffer to account for scheduling drift.
	 *
	 * @since  1.1.0
	 * @return array {
	 *     @type int  $deactivated_count Number of rules deactivated this run.
	 *     @type int  $activated_count   Number of rules that became active this run.
	 *     @type bool $cache_cleared     Whether WooCommerce price caches were flushed.
	 * }
	 */
	public function deactivate_expired_rules() {
		global $wpdb;

		$results = array(
			'deactivated_count' => 0,
			'activated_count'   => 0,
			'cache_cleared'     => false,
		);

		$table = $wpdb->prefix . 'pricing_rules';

		/*
		 * Rule schedule timestamps are stored in UTC, so UTC_TIMESTAMP() keeps the expiry
		 * comparison timezone-safe regardless of the site's configured local timezone.
		 */
		$expired_rules = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be parameterized; this query uses a trusted prefixed table name only.
			'SELECT rule_id FROM ' . $table . '
            WHERE is_active = 1
            AND end_date IS NOT NULL
            AND end_date < UTC_TIMESTAMP()'
		);

		if ( ! empty( $expired_rules ) ) {
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be parameterized; placeholders are used for all dynamic IDs.
					'UPDATE ' . $table . ' SET is_active = 0 WHERE rule_id IN (' . implode( ',', array_fill( 0, count( $expired_rules ), '%d' ) ) . ')',
					...$expired_rules
				)
			);

			$results['deactivated_count'] = count( $expired_rules );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$this->utils->log_error(
					'Auto-deactivated expired pricing rules',
					array( 'rule_ids' => $expired_rules ),
					'debug'
				);
			}
		}

		$newly_active_rules = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be parameterized; this query uses a trusted prefixed table name only.
			'SELECT rule_id FROM ' . $table . '
            WHERE is_active = 1
            AND start_date IS NOT NULL
            AND start_date <= UTC_TIMESTAMP()
            AND start_date > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 MINUTE)
            AND (end_date IS NULL OR end_date >= UTC_TIMESTAMP())'
		);

		if ( ! empty( $newly_active_rules ) ) {
			$results['activated_count'] = count( $newly_active_rules );
		}

		$affected_rule_ids = array_values( array_unique( array_merge( $expired_rules, $newly_active_rules ) ) );
		if ( ! empty( $affected_rule_ids ) ) {
			$this->clear_woocommerce_price_caches( $affected_rule_ids );
			$results['cache_cleared'] = true;
		}

		update_option( 'wccg_last_expiration_check', time() );

		return $results;
	}

	/**
	 * Clear WooCommerce price caches for the products affected by the supplied rule IDs.
	 *
	 * @since  1.2.0
	 * @param  int[] $rule_ids Pricing rule IDs.
	 * @return void
	 */
	public function refresh_price_caches_for_rule_ids( $rule_ids ) {
		$rule_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $rule_ids ) ) ) );
		if ( empty( $rule_ids ) ) {
			return;
		}

		$this->clear_woocommerce_price_caches( $rule_ids );
	}

	/**
	 * Clear WooCommerce product transient caches affected by the supplied rule IDs.
	 *
	 * @since  1.2.0
	 * @param  int[] $rule_ids Pricing rule IDs.
	 * @return void
	 */
	private function clear_woocommerce_price_caches( $rule_ids = array() ) {
		global $wpdb;

		$rule_ids = array_values( array_unique( array_filter( array_map( 'absint', $rule_ids ) ) ) );
		if ( function_exists( 'wc_delete_product_transients' ) && ! empty( $rule_ids ) ) {
			$product_ids = $this->get_affected_product_ids_for_rules( $rule_ids );

			foreach ( $product_ids as $product_id ) {
				wc_delete_product_transients( $product_id );
			}
		}

		/**
		 * Fires after WooCommerce product transients are cleared so dependent caches can refresh.
		 *
		 * @since 1.0.0
		 */
		do_action( 'woocommerce_delete_product_transients' );
	}

	/**
	 * Resolve all product IDs affected by the supplied pricing rules.
	 *
	 * @since  1.2.0
	 * @param  int[] $rule_ids Pricing rule IDs.
	 * @return int[] Unique affected product IDs.
	 */
	private function get_affected_product_ids_for_rules( $rule_ids ) {
		global $wpdb;

		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT product_id
            FROM ' . $wpdb->prefix . 'rule_products
            WHERE rule_id IN (' . implode( ',', array_fill( 0, count( $rule_ids ), '%d' ) ) . ')',
				...$rule_ids
			)
		);

		$category_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT category_id
            FROM ' . $wpdb->prefix . 'rule_categories
            WHERE rule_id IN (' . implode( ',', array_fill( 0, count( $rule_ids ), '%d' ) ) . ')',
				...$rule_ids
			)
		);

		$category_ids = array_values( array_unique( array_filter( array_map( 'absint', $category_ids ) ) ) );
		if ( ! empty( $category_ids ) ) {
			$category_products = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT p.ID
                FROM ' . $wpdb->posts . ' p
                JOIN ' . $wpdb->term_relationships . ' tr ON p.ID = tr.object_id
                JOIN ' . $wpdb->term_taxonomy . ' tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE p.post_type = \'product\'
                AND p.post_status IN (\'publish\', \'private\')
                AND tt.taxonomy = \'product_cat\'
                AND tt.term_id IN (' . implode( ',', array_fill( 0, count( $category_ids ), '%d' ) ) . ')',
					...$category_ids
				)
			);
			$product_ids       = array_merge( $product_ids, $category_products );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
	}

	/**
	 * Execute all daily maintenance tasks: orphaned data cleanup, log pruning, and
	 * orphaned group assignment removal.
	 *
	 * @since  1.0.0
	 * @return bool True if all tasks succeeded, false if any task failed.
	 */
	public function run_cleanup_tasks() {
		try {
			$start_time     = microtime( true );
			$results        = array(
				'orphaned_data'     => $this->db->cleanup_orphaned_data(),
				'old_logs'          => $this->db->cleanup_old_logs(),
				'group_assignments' => $this->db->cleanup_orphaned_group_assignments(),
			);
			$execution_time = microtime( true ) - $start_time;

			if ( in_array( false, $results, true ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				$this->utils->log_error(
					'Cleanup tasks completed',
					array(
						'results'        => $results,
						'execution_time' => round( $execution_time, 2 ) . 's',
					),
					in_array( false, $results, true ) ? 'error' : 'debug'
				);
			}

			$cleanup_succeeded = ! in_array( false, $results, true );
			if ( $cleanup_succeeded ) {
				update_option( 'wccg_last_cleanup', time() );
			}

			return $cleanup_succeeded;
		} catch ( Exception $e ) {
			$this->utils->log_error(
				'Cleanup tasks failed: ' . $e->getMessage(),
				array(),
				'critical'
			);
			return false;
		}
	}

	/**
	 * Remove a deleted user's group assignment immediately.
	 *
	 * @since  1.2.0
	 * @param  int $user_id Deleted WordPress user ID.
	 * @return void
	 */
	public function handle_deleted_user( $user_id ) {
		$this->db->cleanup_deleted_user_assignment( $user_id );
	}

	/**
	 * Remove product-specific rule links immediately when a product or variation is deleted.
	 *
	 * @since  1.2.0
	 * @param  int $post_id Deleted post ID.
	 * @return void
	 */
	public function handle_deleted_post( $post_id ) {
		$post_type = get_post_type( $post_id );
		if ( ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}

		$this->db->cleanup_deleted_product_rule_links( $post_id );
	}

	/**
	 * Remove category-specific rule links immediately when a product category is deleted.
	 *
	 * @since  1.2.0
	 * @param  int    $term_id  Deleted term ID.
	 * @param  int    $tt_id    Deleted term taxonomy ID.
	 * @param  string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function handle_deleted_term( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );

		if ( 'product_cat' !== $taxonomy ) {
			return;
		}

		$this->db->cleanup_deleted_category_rule_links( $term_id );
	}

	/**
	 * Return the current cron schedule status and last-run information.
	 *
	 * @since  1.0.0
	 * @return array {
	 *     @type bool        $is_scheduled Whether the cleanup cron is currently scheduled.
	 *     @type string|null $next_run     Local datetime of next scheduled run, or null.
	 *     @type string|null $last_run     Local datetime of last completed run, or null.
	 *     @type int         $log_count    Total number of entries in the error log table.
	 * }
	 */
	public function get_cleanup_status() {
		$next_run = wp_next_scheduled( 'wccg_cleanup_cron' );
		$last_run = get_option( 'wccg_last_cleanup', 0 );

		return array(
			'is_scheduled' => (bool) $next_run,
			'next_run'     => $next_run ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_run ), 'Y-m-d H:i:s' ) : null,
			'last_run'     => $last_run ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $last_run ), 'Y-m-d H:i:s' ) : null,
			'log_count'    => $this->utils->get_log_count(),
		);
	}

	/**
	 * Determine whether a timestamp option indicates the task ran recently enough.
	 *
	 * @since  1.2.0
	 * @param  string $option_name Option name storing a Unix timestamp.
	 * @param  int    $interval    Interval in seconds.
	 * @return bool True when the task ran recently.
	 */
	private function is_recent_run( $option_name, $interval ) {
		$last_run = (int) get_option( $option_name, 0 );
		return $last_run > 0 && ( time() - $last_run ) < $interval;
	}

	/**
	 * Execute a callback under a transient lock to avoid duplicate background work.
	 *
	 * @since  1.2.0
	 * @param  string   $lock_key         Transient key.
	 * @param  int      $lock_ttl_seconds Lock lifetime in seconds.
	 * @param  callable $callback         Callback to execute.
	 * @return void
	 */
	private function run_with_lock( $lock_key, $lock_ttl_seconds, $callback ) {
		if ( get_transient( $lock_key ) ) {
			return;
		}

		set_transient( $lock_key, 1, $lock_ttl_seconds );
		try {
			call_user_func( $callback );
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Return human-readable messages for any product or category rules that have duplicates.
	 *
	 * @since  1.0.0
	 * @return string[] Array of conflict description strings.
	 */
	public function detect_pricing_rule_conflicts() {
		global $wpdb;

		$conflicts       = array();
		$duplicate_rules = $wpdb->get_results(
			"SELECT p.product_id, pr.group_id, COUNT(*) as rule_count, g.group_name
            FROM {$wpdb->prefix}rule_products p
            JOIN {$wpdb->prefix}pricing_rules pr ON p.rule_id = pr.rule_id
            JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
            GROUP BY p.product_id, pr.group_id
            HAVING rule_count > 1"
		);

		foreach ( $duplicate_rules as $rule ) {
			$product = wc_get_product( $rule->product_id );
			if ( $product ) {
				$conflicts[] = sprintf(
					'Product "%s" has multiple rules for group: %s',
					$product->get_name(),
					$rule->group_name
				);
			}
		}

		$duplicate_category_rules = $wpdb->get_results(
			"SELECT rc.category_id, pr.group_id, COUNT(*) as rule_count, g.group_name
            FROM {$wpdb->prefix}rule_categories rc
            JOIN {$wpdb->prefix}pricing_rules pr ON rc.rule_id = pr.rule_id
            JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
            GROUP BY rc.category_id, pr.group_id
            HAVING rule_count > 1"
		);

		foreach ( $duplicate_category_rules as $rule ) {
			$category = get_term( $rule->category_id, 'product_cat' );
			if ( $category && ! is_wp_error( $category ) ) {
				$conflicts[] = sprintf(
					'Category "%s" has multiple rules for group: %s',
					$category->name,
					$rule->group_name
				);
			}
		}

		return $conflicts;
	}
}
