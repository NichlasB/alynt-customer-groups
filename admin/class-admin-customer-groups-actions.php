<?php
/**
 * Form action handlers for customer group create, delete, and default-group operations.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Processes POST submissions on the Customer Groups admin page.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_Customer_Groups_Actions {
    private static $instance = null;
    private $db;
    private $utils;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Admin_Customer_Groups_Actions
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->db    = WCCG_Database::instance();
        $this->utils = WCCG_Utilities::instance();
    }

    /**
     * Route an incoming POST request to the appropriate action handler.
     *
     * Verifies the wccg_customer_groups_action nonce before dispatching.
     *
     * @since  1.0.0
     * @return void
     */
    public function handle_form_submission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!isset($_POST['wccg_customer_groups_nonce']) || !wp_verify_nonce($_POST['wccg_customer_groups_nonce'], 'wccg_customer_groups_action')) {
            wp_die(esc_html__('Security check failed.', 'alynt-customer-groups'));
        }

        $action = $this->utils->sanitize_input($_POST['action']);

        switch ($action) {
            case 'add_group':
                $this->handle_add_group();
                break;
            case 'delete_group':
                $this->handle_delete_group();
                break;
            case 'set_default_group':
                $this->handle_set_default_group();
                break;
        }
    }

    private function handle_add_group() {
        $group_name        = $this->utils->sanitize_input($_POST['group_name']);
        $group_description = $this->utils->sanitize_input($_POST['group_description'], 'textarea');

        if (empty($group_name)) {
            $this->add_admin_notice('error', __('Group name is required.', 'alynt-customer-groups'));
            return;
        }

        $result = $this->db->transaction(function() use ($group_name, $group_description) {
            global $wpdb;

            return $wpdb->insert(
                $wpdb->prefix . 'customer_groups',
                array(
                    'group_name'        => $group_name,
                    'group_description' => $group_description,
                ),
                array('%s', '%s')
            );
        });

        if ($result) {
            $this->add_admin_notice('success', __('Customer group added successfully.', 'alynt-customer-groups'));
            return;
        }

        $this->add_admin_notice('error', __('Error adding customer group.', 'alynt-customer-groups'));
    }

    private function handle_delete_group() {
        $group_id = $this->utils->sanitize_input($_POST['group_id'], 'group_id');
        if (!$group_id) {
            $this->add_admin_notice('error', __('Invalid group ID.', 'alynt-customer-groups'));
            return;
        }

        $default_group_id = get_option('wccg_default_group_id', 0);
        if ((int) $default_group_id === (int) $group_id) {
            $this->add_admin_notice('error', __('Cannot delete the default group for ungrouped customers. Please set a different default group first.', 'alynt-customer-groups'));
            return;
        }

        $result = $this->db->transaction(function() use ($group_id) {
            global $wpdb;

            $this->db->delete_group_pricing_rules($group_id);
            $wpdb->delete($wpdb->prefix . 'user_groups', array('group_id' => $group_id), array('%d'));

            return $wpdb->delete(
                $wpdb->prefix . 'customer_groups',
                array('group_id' => $group_id),
                array('%d')
            );
        });

        if ($result) {
            $this->add_admin_notice('success', __('Customer group and associated data deleted successfully.', 'alynt-customer-groups'));
            return;
        }

        $this->add_admin_notice('error', __('Error deleting customer group.', 'alynt-customer-groups'));
    }

    private function handle_set_default_group() {
        $group_id     = $this->utils->sanitize_input($_POST['default_group_id'], 'int');
        $custom_title = isset($_POST['custom_title']) ? $this->utils->sanitize_input($_POST['custom_title']) : '';

        if ($group_id < 0) {
            $this->add_admin_notice('error', __('Invalid group ID.', 'alynt-customer-groups'));
            return;
        }

        if ($group_id > 0) {
            global $wpdb;

            $group_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT group_id FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
                $group_id
            ));

            if (!$group_exists) {
                $this->add_admin_notice('error', __('Selected group does not exist.', 'alynt-customer-groups'));
                return;
            }
        }

        update_option('wccg_default_group_id', $group_id);
        update_option('wccg_default_group_custom_title', $custom_title);

        if ($group_id > 0) {
            $this->add_admin_notice('success', __('Default group for ungrouped customers updated successfully.', 'alynt-customer-groups'));
            return;
        }

        $this->add_admin_notice('success', __('Default group disabled. Ungrouped customers will see regular prices.', 'alynt-customer-groups'));
    }

    private function add_admin_notice($type, $message) {
        add_settings_error(
            'wccg_customer_groups',
            'wccg_notice',
            $message,
            $type
        );
    }
}
