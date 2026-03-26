<?php
/**
 * Data queries for the User Assignments admin page.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetches paginated, filtered user lists and supporting data for the User Assignments screen.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_User_Assignments_Query {
    private static $instance = null;
    private $db;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Admin_User_Assignments_Query
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
     * Fetch a paginated list of users matching the given filter parameters.
     *
     * First and last name meta values are appended to each user object.
     *
     * @since  1.0.0
     * @param  array $params {
     *     @type string $search         Search term.
     *     @type int    $users_per_page Number of results per page.
     *     @type int    $current_page   Current page number.
     *     @type string $orderby        Column to order by.
     *     @type string $order          'ASC' or 'DESC'.
     *     @type int    $group_filter   Filter by group ID (0 = all groups).
     *     @type string $date_from      Registration date lower bound.
     *     @type string $date_to        Registration date upper bound.
     * }
     * @return WP_User[] Array of user objects with first_name and last_name appended.
     */
    public function get_users($params) {
        $args = array(
            'fields'  => array('ID', 'user_login', 'user_email', 'user_registered'),
            'number'  => $params['users_per_page'],
            'paged'   => $params['current_page'],
            'orderby' => $params['orderby'],
            'order'   => $params['order']
        );

        if (!empty($params['date_from']) || !empty($params['date_to'])) {
            $date_query = array();
            if (!empty($params['date_from'])) {
                $date_query['after'] = $params['date_from'];
            }
            if (!empty($params['date_to'])) {
                $date_query['before'] = $params['date_to'];
            }
            $date_query['inclusive'] = true;
            $args['date_query'] = array($date_query);
        }

        if (!empty($params['search'])) {
            $args['search'] = '*' . $params['search'] . '*';
            $args['search_columns'] = array('user_login', 'user_email', 'display_name');
        }

        if ($params['group_filter'] > 0) {
            $users_in_group  = $this->db->get_users_in_group($params['group_filter']);
            $args['include'] = !empty($users_in_group) ? $users_in_group : array(0);
        }

        $users = get_users($args);
        foreach ($users as $user) {
            $user->first_name = get_user_meta($user->ID, 'first_name', true);
            $user->last_name  = get_user_meta($user->ID, 'last_name', true);
        }

        return $users;
    }

    /**
     * Count total users matching the given filter parameters (ignores pagination).
     *
     * @since  1.0.0
     * @param  array $params Same filter params as get_users(), pagination fields are ignored.
     * @return int Total matching user count.
     */
    public function get_total_users($params) {
        $args = array(
            'fields' => 'ID',
            'number' => -1
        );

        if ($params['group_filter'] > 0) {
            $users_in_group  = $this->db->get_users_in_group($params['group_filter']);
            $args['include'] = !empty($users_in_group) ? $users_in_group : array(0);
        }

        return count(get_users($args));
    }

    /**
     * Fetch all customer groups ordered by name.
     *
     * @since  1.0.0
     * @return object[] Array of stdClass rows from the customer_groups table.
     */
    public function get_groups() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}customer_groups ORDER BY group_name ASC");
    }

    /**
     * Fetch all user-group assignments keyed by user_id.
     *
     * @since  1.0.0
     * @return object[] Associative array of stdClass rows from the user_groups table, keyed by user_id.
     */
    public function get_user_groups() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}user_groups", OBJECT_K);
    }

    /**
     * Resolve the display name of a user's group, cleaning up orphaned assignments if needed.
     *
     * @since  1.0.0
     * @param  int      $user_id     WordPress user ID.
     * @param  object[] $user_groups Keyed user-group rows from get_user_groups().
     * @param  object[] $groups      Group rows from get_groups().
     * @return string Group name, or 'Unassigned' if the user has no group.
     */
    public function get_user_group_name($user_id, $user_groups, $groups) {
        if (!isset($user_groups[$user_id])) {
            return 'Unassigned';
        }

        $group_id = $user_groups[$user_id]->group_id;
        foreach ($groups as $group) {
            if ($group->group_id === $group_id) {
                return esc_html($group->group_name);
            }
        }

        // Assignment references a deleted group — clean it up opportunistically.
        $this->db->cleanup_orphaned_group_assignments();
        return 'Unassigned';
    }
}
