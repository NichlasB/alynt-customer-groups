<?php
/**
 * Database maintenance operations: cleanup and schema upgrades.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Removes orphaned data, prunes old error logs, and runs version-specific schema migrations.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Maintenance_Repository {
    private static $instance = null;
    private $db;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Maintenance_Repository
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

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

        return $this->db->transaction(function() use ($wpdb) {
            $cleanup_operations = array(
                'user_assignments' => array(
                    'table' => $this->db->get_table_name('user_groups'),
                    'query' => "DELETE ug FROM {$this->db->get_table_name('user_groups')} ug
                    LEFT JOIN {$wpdb->users} u ON ug.user_id = u.ID
                    WHERE u.ID IS NULL"
                ),
                'product_rules' => array(
                    'table' => $this->db->get_table_name('rule_products'),
                    'query' => "DELETE rp FROM {$this->db->get_table_name('rule_products')} rp
                    LEFT JOIN {$wpdb->posts} p ON rp.product_id = p.ID
                    WHERE p.ID IS NULL"
                ),
                'category_rules' => array(
                    'table' => $this->db->get_table_name('rule_categories'),
                    'query' => "DELETE rc FROM {$this->db->get_table_name('rule_categories')} rc
                    LEFT JOIN {$wpdb->term_taxonomy} tt ON rc.category_id = tt.term_id
                    WHERE tt.term_id IS NULL"
                )
            );

            foreach ($cleanup_operations as $operation => $details) {
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$details['table']}'") === $details['table'];
                if (!$table_exists) {
                    continue;
                }

                $result = $wpdb->query($details['query']);
                if ($result === false) {
                    throw new Exception("Failed to cleanup {$operation}");
                }
            }

            return true;
        });
    }

    /**
     * Delete error log entries older than 30 days (non-critical) or 90 days (critical).
     *
     * @since  1.0.0
     * @return bool True on success, false if the log table does not exist.
     */
    public function cleanup_old_logs() {
        global $wpdb;

        return $this->db->transaction(function() use ($wpdb) {
            $table_name = $this->db->get_table_name('error_log');
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

            if (!$table_exists) {
                return false;
            }

            $wpdb->query(
                "DELETE FROM $table_name
                WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND severity != 'critical'"
            );

            $wpdb->query(
                "DELETE FROM $table_name
                WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)
                AND severity = 'critical'"
            );

            return true;
        });
    }

    /**
     * Run version-specific schema migration routines.
     *
     * @since  1.0.0
     * @param  string $installed_version The previously installed plugin version string.
     * @return bool True if all applicable upgrades succeeded, false if any failed.
     */
    public function run_upgrades($installed_version) {
        global $wpdb;

        $success = true;

        if (version_compare($installed_version, '1.1.0', '<')) {
            try {
                $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->db->get_table_name('pricing_rules')} LIKE 'created_at'");
                if (empty($column_exists)) {
                    $wpdb->query("ALTER TABLE {$this->db->get_table_name('pricing_rules')}
                        ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        ADD INDEX created_at (created_at)");

                    $column_check = $wpdb->get_results("SHOW COLUMNS FROM {$this->db->get_table_name('pricing_rules')} LIKE 'created_at'");
                    if (empty($column_check)) {
                        throw new Exception('Failed to add created_at column');
                    }

                    $wpdb->query("UPDATE {$this->db->get_table_name('pricing_rules')}
                        SET created_at = CURRENT_TIMESTAMP
                        WHERE created_at IS NULL");
                }
            } catch (Exception $e) {
                WCCG_Logger::instance()->log_error(
                    'Database upgrade failed: ' . $e->getMessage(),
                    array(
                        'version' => $installed_version,
                        'trace'   => $e->getTraceAsString()
                    ),
                    'critical'
                );
                $success = false;
            }
        }

        return $success;
    }
}
