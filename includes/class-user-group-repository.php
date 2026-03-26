<?php
/**
 * Database read/write operations for user-group assignments.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Queries and updates the user_groups table that maps WordPress users to customer groups.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_User_Group_Repository {
    private static $instance = null;
    private $db;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_User_Group_Repository
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
     * Get the group ID assigned to a user.
     *
     * @since  1.0.0
     * @param  int $user_id WordPress user ID.
     * @return string|null Group ID as a string, or null if the user has no group assignment.
     */
    public function get_user_group($user_id) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT group_id FROM {$this->db->get_table_name('user_groups')} WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get the group name for a user.
     *
     * @since  1.0.0
     * @param  int $user_id WordPress user ID.
     * @return string|null Group name, or null if the user has no group assignment.
     */
    public function get_user_group_name($user_id) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT g.group_name
            FROM {$this->db->get_table_name('groups')} g
            JOIN {$this->db->get_table_name('user_groups')} ug ON g.group_id = ug.group_id
            WHERE ug.user_id = %d",
            $user_id
        ));
    }

    /**
     * Get all user IDs assigned to a group.
     *
     * @since  1.0.0
     * @param  int $group_id Customer group ID.
     * @return array Array of user ID strings.
     */
    public function get_users_in_group($group_id) {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare(
            "SELECT user_id
            FROM {$this->db->get_table_name('user_groups')}
            WHERE group_id = %d",
            $group_id
        ));
    }

    /**
     * Assign multiple users to a group, replacing any existing assignments in batches of 1000.
     *
     * @since  1.0.0
     * @param  int[] $user_ids Array of WordPress user IDs.
     * @param  int   $group_id Target customer group ID.
     * @return bool True on success, false if any batch fails or inputs are empty.
     */
    public function bulk_assign_user_groups($user_ids, $group_id) {
        if (empty($user_ids) || !$group_id) {
            return false;
        }

        return $this->db->transaction(function() use ($user_ids, $group_id) {
            foreach (array_chunk($user_ids, 1000) as $batch_user_ids) {
                $result = $this->replace_batch_assignments($batch_user_ids, $group_id);
                if ($result === false) {
                    throw new Exception('Batch assignment failed');
                }
            }

            return true;
        });
    }

    /**
     * Remove user-group assignments whose group no longer exists in the groups table.
     *
     * @since  1.0.0
     * @return bool True on success, false on transaction failure.
     */
    public function cleanup_orphaned_group_assignments() {
        global $wpdb;

        return $this->db->transaction(function() use ($wpdb) {
            $result = $wpdb->query("
                DELETE ug
                FROM {$this->db->get_table_name('user_groups')} ug
                LEFT JOIN {$this->db->get_table_name('groups')} g ON ug.group_id = g.group_id
                WHERE g.group_id IS NULL
            ");

            return $result !== false;
        });
    }

    private function replace_batch_assignments($batch_user_ids, $group_id) {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($batch_user_ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->db->get_table_name('user_groups')} WHERE user_id IN ($placeholders)",
            $batch_user_ids
        ));

        $values = array();
        $value_placeholders = array();

        foreach ($batch_user_ids as $user_id) {
            $values[] = $user_id;
            $values[] = $group_id;
            $value_placeholders[] = '(%d, %d)';
        }

        $query = $wpdb->prepare(
            "INSERT INTO {$this->db->get_table_name('user_groups')} (user_id, group_id) VALUES " . implode(',', $value_placeholders),
            $values
        );

        return $wpdb->query($query);
    }
}
