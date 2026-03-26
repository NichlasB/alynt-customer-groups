<?php
/**
 * Core plugin functionality: cron verification, expiration checks, and cleanup.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
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
    private static $instance = null;
    private $db;
    private $utils;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Core
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->db = WCCG_Database::instance();
        $this->utils = WCCG_Utilities::instance();
        $this->init_hooks();
    }

    private function init_hooks() {
        /**
         * Fires when the plugin's daily maintenance cron event runs.
         *
         * @since 1.0.0
         */
        add_action('wccg_cleanup_cron', array($this, 'run_cleanup_tasks'));

        /**
         * Fires every five minutes to disable expired pricing rules and refresh price caches.
         *
         * @since 1.1.0
         */
        add_action('wccg_check_expired_rules', array($this, 'deactivate_expired_rules'));

        /**
         * Fires during every admin request so the daily cleanup cron can be rescheduled if needed.
         *
         * @since 1.0.0
         */
        add_action('admin_init', array($this, 'verify_cleanup_schedule'));

        /**
         * Fires during every admin request so the 5-minute expiration cron can be rescheduled if needed.
         *
         * @since 1.1.0
         */
        add_action('admin_init', array($this, 'verify_expiration_schedule'));
    }

    /**
     * Ensure the daily cleanup cron event is scheduled; reschedule it if missing.
     *
     * @since  1.0.0
     * @return void
     */
    public function verify_cleanup_schedule() {
        if (!wp_next_scheduled('wccg_cleanup_cron')) {
            wp_schedule_event(strtotime('tomorrow 2am'), 'daily', 'wccg_cleanup_cron');
        }
    }

    /**
     * Ensure the 5-minute expiration-check cron event is scheduled; reschedule it if missing.
     *
     * @since  1.1.0
     * @return void
     */
    public function verify_expiration_schedule() {
        if (!wp_next_scheduled('wccg_check_expired_rules')) {
            wp_schedule_event(time(), 'wccg_five_minutes', 'wccg_check_expired_rules');
        }
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
            'cache_cleared'     => false
        );

        $table = $wpdb->prefix . 'pricing_rules';
        $expired_rules = $wpdb->get_col(
            /*
             * Rule schedule timestamps are stored in UTC, so UTC_TIMESTAMP() keeps the expiry
             * comparison timezone-safe regardless of the site's configured local timezone.
             */
            "SELECT rule_id FROM {$table}
            WHERE is_active = 1
            AND end_date IS NOT NULL
            AND end_date < UTC_TIMESTAMP()"
        );

        if (!empty($expired_rules)) {
            $placeholders = implode(',', array_fill(0, count($expired_rules), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET is_active = 0 WHERE rule_id IN ($placeholders)",
                $expired_rules
            ));

            $results['deactivated_count'] = count($expired_rules);
            $this->clear_woocommerce_price_caches();
            $results['cache_cleared'] = true;

            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->utils->log_error(
                    'Auto-deactivated expired pricing rules',
                    array('rule_ids' => $expired_rules),
                    'debug'
                );
            }
        }

        $newly_active_rules = $wpdb->get_col(
            "SELECT rule_id FROM {$table}
            WHERE is_active = 1
            AND start_date IS NOT NULL
            AND start_date <= UTC_TIMESTAMP()
            AND start_date > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 MINUTE)
            AND (end_date IS NULL OR end_date >= UTC_TIMESTAMP())"
        );

        if (!empty($newly_active_rules)) {
            $results['activated_count'] = count($newly_active_rules);
            if (!$results['cache_cleared']) {
                $this->clear_woocommerce_price_caches();
                $results['cache_cleared'] = true;
            }
        }

        return $results;
    }

    private function clear_woocommerce_price_caches() {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_wc_var_prices_%'
            OR option_name LIKE '_transient_timeout_wc_var_prices_%'
            OR option_name LIKE '_transient_wc_product_children_%'
            OR option_name LIKE '_transient_timeout_wc_product_children_%'"
        );

        if (function_exists('wc_delete_product_transients')) {
            $product_ids = $wpdb->get_col(
                "SELECT DISTINCT product_id FROM {$wpdb->prefix}rule_products"
            );

            foreach ($product_ids as $product_id) {
                wc_delete_product_transients($product_id);
            }
        }

        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('woocommerce');
        } elseif (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        /**
         * Fires after WooCommerce product transients are cleared so dependent caches can refresh.
         *
         * @since 1.0.0
         */
        do_action('woocommerce_delete_product_transients');
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
            $start_time = microtime(true);
            $results = array(
                'orphaned_data'      => $this->db->cleanup_orphaned_data(),
                'old_logs'           => $this->db->cleanup_old_logs(),
                'group_assignments'  => $this->db->cleanup_orphaned_group_assignments()
            );
            $execution_time = microtime(true) - $start_time;

            if (in_array(false, $results, true) || (defined('WP_DEBUG') && WP_DEBUG)) {
                $this->utils->log_error(
                    'Cleanup tasks completed',
                    array(
                        'results'        => $results,
                        'execution_time' => round($execution_time, 2) . 's'
                    ),
                    in_array(false, $results, true) ? 'error' : 'debug'
                );
            }

            return !in_array(false, $results, true);
        } catch (Exception $e) {
            $this->utils->log_error(
                'Cleanup tasks failed: ' . $e->getMessage(),
                array('trace' => $e->getTraceAsString()),
                'critical'
            );
            return false;
        }
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
        $next_run = wp_next_scheduled('wccg_cleanup_cron');
        $last_run = get_option('wccg_last_cleanup', 0);

        return array(
            'is_scheduled' => (bool) $next_run,
            'next_run'     => $next_run ? get_date_from_gmt(date('Y-m-d H:i:s', $next_run), 'Y-m-d H:i:s') : null,
            'last_run'     => $last_run ? get_date_from_gmt(date('Y-m-d H:i:s', $last_run), 'Y-m-d H:i:s') : null,
            'log_count'    => $this->utils->get_log_count()
        );
    }

    /**
     * Return human-readable messages for any product or category rules that have duplicates.
     *
     * @since  1.0.0
     * @return string[] Array of conflict description strings.
     */
    public function detect_pricing_rule_conflicts() {
        global $wpdb;

        $conflicts = array();
        $duplicate_rules = $wpdb->get_results(
            "SELECT p.product_id, pr.group_id, COUNT(*) as rule_count, g.group_name
            FROM {$wpdb->prefix}rule_products p
            JOIN {$wpdb->prefix}pricing_rules pr ON p.rule_id = pr.rule_id
            JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
            GROUP BY p.product_id, pr.group_id
            HAVING rule_count > 1"
        );

        foreach ($duplicate_rules as $rule) {
            $product = wc_get_product($rule->product_id);
            if ($product) {
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

        foreach ($duplicate_category_rules as $rule) {
            $category = get_term($rule->category_id, 'product_cat');
            if ($category && !is_wp_error($category)) {
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
