<?php
/**
 * Form action handlers for pricing rule create and delete operations.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Processes POST submissions on the Pricing Rules admin page.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_Pricing_Rules_Actions {
    private static $instance = null;
    private $db;
    private $utils;
    private $writer;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Admin_Pricing_Rules_Actions
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->db     = WCCG_Database::instance();
        $this->utils  = WCCG_Utilities::instance();
        $this->writer = WCCG_Pricing_Rule_Write_Service::instance();
    }

    /**
     * Route an incoming POST request to the appropriate action handler.
     *
     * Verifies the wccg_pricing_rules_action nonce before dispatching.
     *
     * @since  1.0.0
     * @return void
     */
    public function handle_form_submission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!isset($_POST['wccg_pricing_rules_nonce']) || !wp_verify_nonce($_POST['wccg_pricing_rules_nonce'], 'wccg_pricing_rules_action')) {
            wp_die(esc_html__('Security check failed.', 'alynt-customer-groups'));
        }

        if (!isset($_POST['action'])) {
            return;
        }

        if ($_POST['action'] === 'save_rule') {
            $this->handle_save_rule();
        }

        if ($_POST['action'] === 'delete_rule') {
            $this->handle_delete_rule();
        }
    }

    private function handle_save_rule() {
        $group_id       = $this->utils->sanitize_input($_POST['group_id'], 'group_id');
        $discount_type  = $this->utils->sanitize_input($_POST['discount_type'], 'discount_type');
        $discount_value = $this->utils->sanitize_input($_POST['discount_value'], 'price');

        if ($group_id === 0) {
            $this->add_admin_notice('error', __('Invalid customer group selected.', 'alynt-customer-groups'));
            return;
        }

        $validation_result = $this->utils->validate_pricing_input($discount_type, $discount_value);
        if (!$validation_result['valid']) {
            $this->add_admin_notice('error', $validation_result['message']);
            return;
        }

        $start_date = !empty($_POST['start_date']) ? $this->writer->convert_to_utc($_POST['start_date']) : null;
        $end_date   = !empty($_POST['end_date']) ? $this->writer->convert_to_utc($_POST['end_date']) : null;
        if ($start_date && $end_date && $end_date <= $start_date) {
            $this->add_admin_notice('error', __('End date must be after start date.', 'alynt-customer-groups'));
            return;
        }

        $product_ids  = isset($_POST['product_ids']) ? array_filter(array_map('intval', (array) $_POST['product_ids'])) : array();
        $category_ids = isset($_POST['category_ids']) ? array_filter(array_map('intval', (array) $_POST['category_ids'])) : array();
        $result       = $this->writer->save_pricing_rule($group_id, $discount_type, $discount_value, $product_ids, $category_ids, $start_date, $end_date);

        $this->add_admin_notice(
            $result ? 'success' : 'error',
            $result
                ? __('Pricing rule saved successfully.', 'alynt-customer-groups')
                : __('Error occurred while saving pricing rule.', 'alynt-customer-groups')
        );
    }

    private function handle_delete_rule() {
        $rule_id = $this->utils->sanitize_input($_POST['rule_id'], 'int');
        if (!$rule_id) {
            $this->add_admin_notice('error', __('Invalid rule ID.', 'alynt-customer-groups'));
            return;
        }

        $result = $this->db->transaction(function() use ($rule_id) {
            global $wpdb;
            $wpdb->delete($wpdb->prefix . 'rule_products', array('rule_id' => $rule_id), array('%d'));
            $wpdb->delete($wpdb->prefix . 'rule_categories', array('rule_id' => $rule_id), array('%d'));
            return $wpdb->delete($wpdb->prefix . 'pricing_rules', array('rule_id' => $rule_id), array('%d'));
        });

        $this->add_admin_notice(
            $result !== false ? 'success' : 'error',
            $result !== false
                ? __('Pricing rule deleted successfully.', 'alynt-customer-groups')
                : __('Error deleting pricing rule.', 'alynt-customer-groups')
        );
    }

    private function add_admin_notice($type, $message) {
        add_settings_error('wccg_pricing_rules', 'wccg_notice', $message, $type);
    }
}
