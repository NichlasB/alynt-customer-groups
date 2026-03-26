<?php
/**
 * User Assignments admin page: display and sorting.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the User Assignments admin page, handling query parameter parsing
 * and column sorting URL generation.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_User_Assignments {
    private static $instance = null;
    private $utils;
    private $query;
    private $actions;
    private $params;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Admin_User_Assignments
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->utils   = WCCG_Utilities::instance();
        $this->query   = WCCG_Admin_User_Assignments_Query::instance();
        $this->actions = WCCG_Admin_User_Assignments_Actions::instance();
    }

    /**
     * Handle any pending form submissions, then render the user assignments page.
     *
     * @since  1.0.0
     * @return void
     */
    public function display_page() {
        $this->utils->verify_admin_access();
        $this->setup_page_params();
        $this->actions->handle_form_submission();

        $users       = $this->query->get_users($this->params);
        $total_users = $this->query->get_total_users($this->params);
        $groups      = $this->query->get_groups();
        $user_groups = $this->query->get_user_groups();
        $sorting_urls = array(
            'display_name'    => $this->get_sorting_url('display_name'),
            'user_email'      => $this->get_sorting_url('user_email'),
            'user_registered' => $this->get_sorting_url('user_registered')
        );
        $sort_indicators = array(
            'display_name'    => $this->get_sort_indicator('display_name'),
            'user_email'      => $this->get_sort_indicator('user_email'),
            'user_registered' => $this->get_sort_indicator('user_registered')
        );
        $params = $this->params;
        $query  = $this->query;

        include WCCG_PATH . 'admin/views/html-user-assignments-page.php';
    }

    private function setup_page_params() {
        $this->params = array(
            'search'         => $this->utils->sanitize_input($_GET['search'] ?? ''),
            'users_per_page' => $this->utils->sanitize_input($_GET['per_page'] ?? 100, 'int'),
            'current_page'   => $this->utils->sanitize_input($_GET['paged'] ?? 1, 'int'),
            'orderby'        => $this->utils->sanitize_input($_GET['orderby'] ?? 'ID'),
            'order'          => $this->utils->sanitize_input($_GET['order'] ?? 'ASC'),
            'group_filter'   => $this->utils->sanitize_input($_GET['group_filter'] ?? 0, 'group_id'),
            'date_from'      => $this->utils->sanitize_input($_GET['date_from'] ?? ''),
            'date_to'        => $this->utils->sanitize_input($_GET['date_to'] ?? '')
        );

        $allowed_per_page = array(100, 200, 500, 1000);
        if (!in_array($this->params['users_per_page'], $allowed_per_page, true)) {
            $this->params['users_per_page'] = 100;
        }

        $allowed_orderby = array('ID', 'display_name', 'user_login', 'first_name', 'last_name', 'user_email', 'user_registered');
        if (!in_array($this->params['orderby'], $allowed_orderby, true)) {
            $this->params['orderby'] = 'ID';
        }

        $this->params['order'] = strtoupper($this->params['order']);
        if (!in_array($this->params['order'], array('ASC', 'DESC'), true)) {
            $this->params['order'] = 'ASC';
        }
    }

    private function get_sorting_url($column) {
        $new_order = ($this->params['orderby'] === $column && $this->params['order'] === 'ASC') ? 'DESC' : 'ASC';

        return add_query_arg(array(
            'orderby'      => $column,
            'order'        => $new_order,
            'search'       => $this->params['search'],
            'per_page'     => $this->params['users_per_page'],
            'paged'        => 1,
            'group_filter' => $this->params['group_filter'],
            'date_from'    => $this->params['date_from'],
            'date_to'      => $this->params['date_to']
        ));
    }

    private function get_sort_indicator($column) {
        if ($this->params['orderby'] === $column) {
            return ($this->params['order'] === 'ASC') ? ' ↑' : ' ↓';
        }

        return '';
    }
}
