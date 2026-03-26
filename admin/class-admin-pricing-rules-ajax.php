<?php
/**
 * AJAX endpoint handlers for the Pricing Rules admin screen.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and handles all wp_ajax_wccg_* endpoints used by the Pricing Rules admin UI.
 *
 * All endpoints require the manage_woocommerce capability and verify the
 * wccg_pricing_rules_ajax nonce passed as $_POST['nonce'].
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_Pricing_Rules_Ajax {
    private static $instance = null;
    private $db;
    private $utils;
    private $writer;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Admin_Pricing_Rules_Ajax
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
     * Register all AJAX action hooks.
     *
     * @since  1.0.0
     * @return void
     */
    public function register_hooks() {
        /**
         * Fires for authenticated AJAX requests that toggle a single pricing rule.
         *
         * @since 1.0.0
         */
        add_action('wp_ajax_wccg_toggle_pricing_rule', array($this, 'ajax_toggle_pricing_rule'));

        /**
         * Fires for authenticated AJAX requests that delete all pricing rules.
         *
         * @since 1.0.0
         */
        add_action('wp_ajax_wccg_delete_all_pricing_rules', array($this, 'ajax_delete_all_pricing_rules'));

        /**
         * Fires for authenticated AJAX requests that bulk-enable or bulk-disable pricing rules.
         *
         * @since 1.0.0
         */
        add_action('wp_ajax_wccg_bulk_toggle_pricing_rules', array($this, 'ajax_bulk_toggle_pricing_rules'));

        /**
         * Fires for authenticated AJAX requests that persist pricing rule sort order changes.
         *
         * @since 1.0.0
         */
        add_action('wp_ajax_wccg_reorder_pricing_rules', array($this, 'ajax_reorder_pricing_rules'));

        /**
         * Fires for authenticated AJAX requests that update a pricing rule schedule.
         *
         * @since 1.1.0
         */
        add_action('wp_ajax_wccg_update_rule_schedule', array($this, 'ajax_update_rule_schedule'));

        /**
         * Fires for authenticated AJAX requests that update a pricing rule from the edit modal.
         *
         * @since 1.1.0
         */
        add_action('wp_ajax_wccg_update_pricing_rule', array($this, 'ajax_update_pricing_rule'));

        /**
         * Fires for authenticated AJAX requests that fetch rule data for the edit modal.
         *
         * @since 1.1.0
         */
        add_action('wp_ajax_wccg_get_rule_data', array($this, 'ajax_get_rule_data'));
    }

    /**
     * Toggle the is_active flag on a single pricing rule.
     *
     * @since  1.0.0
     * @return void Sends a JSON response and exits.
     */
    public function ajax_toggle_pricing_rule() {
        check_ajax_referer('wccg_pricing_rules_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $rule_id    = isset($_POST['rule_id']) ? intval($_POST['rule_id']) : 0;
        $new_status = isset($_POST['new_status']) ? intval($_POST['new_status']) : null;
        if (!$rule_id || ($new_status !== 0 && $new_status !== 1)) {
            wp_send_json_error(array('message' => 'Invalid request'));
        }

        global $wpdb;
        $table      = $wpdb->prefix . 'pricing_rules';
        $rule_exists = $wpdb->get_var($wpdb->prepare("SELECT rule_id FROM {$table} WHERE rule_id = %d", $rule_id));
        if (!$rule_exists) {
            wp_send_json_error(array('message' => 'Rule not found'));
        }

        $result = $wpdb->update($table, array('is_active' => $new_status), array('rule_id' => $rule_id), array('%d'), array('%d'));
        if ($result === false) {
            wp_send_json_error(array('message' => 'Database error: ' . $wpdb->last_error));
        }

        $current_status = $wpdb->get_var($wpdb->prepare("SELECT is_active FROM {$table} WHERE rule_id = %d", $rule_id));
        if ((int) $current_status !== $new_status) {
            wp_send_json_error(array('message' => 'Status update verification failed.'));
        }

        wp_send_json_success(array('message' => 'Rule status updated', 'is_active' => (int) $current_status));
    }

    /**
     * Delete all pricing rules and their product/category associations.
     *
     * @since  1.0.0
     * @return void Sends a JSON response and exits.
     */
    public function ajax_delete_all_pricing_rules() {
        check_ajax_referer('wccg_pricing_rules_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $result = $this->db->transaction(function() {
            global $wpdb;
            $rule_ids = $wpdb->get_col("SELECT rule_id FROM {$wpdb->prefix}pricing_rules");
            if (empty($rule_ids)) {
                return 0;
            }
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rule_products");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rule_categories");
            return $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}pricing_rules");
        });

        if ($result !== false) {
            wp_send_json_success(array('message' => 'All pricing rules deleted successfully'));
        }

        wp_send_json_error(array('message' => 'Failed to delete pricing rules'));
    }

    /**
     * Set is_active to the same value on all pricing rules.
     *
     * @since  1.0.0
     * @return void Sends a JSON response and exits.
     */
    public function ajax_bulk_toggle_pricing_rules() {
        check_ajax_referer('wccg_pricing_rules_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $status = isset($_POST['status']) ? intval($_POST['status']) : 1;
        global $wpdb;
        $table  = $wpdb->prefix . 'pricing_rules';
        $result = $wpdb->query($wpdb->prepare("UPDATE {$table} SET is_active = %d", $status));
        if ($result !== false) {
            wp_send_json_success(array('message' => sprintf('All pricing rules %s successfully', $status ? 'enabled' : 'disabled'), 'is_active' => $status));
        }

        wp_send_json_error(array('message' => 'Failed to update pricing rules'));
    }

    /**
     * Update the sort_order for a reordered set of rules.
     *
     * @since  1.0.0
     * @return void Sends a JSON response and exits.
     */
    public function ajax_reorder_pricing_rules() {
        check_ajax_referer('wccg_pricing_rules_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $order = isset($_POST['order']) ? array_map('intval', $_POST['order']) : array();
        if (empty($order)) {
            wp_send_json_error(array('message' => 'No order data provided'));
        }

        global $wpdb;
        $table      = $wpdb->prefix . 'pricing_rules';
        $sort_order = 1;
        foreach ($order as $rule_id) {
            $result = $wpdb->update($table, array('sort_order' => $sort_order), array('rule_id' => $rule_id), array('%d'), array('%d'));
            if ($result === false) {
                wp_send_json_error(array('message' => 'Failed to update rule order'));
            }
            $sort_order++;
        }

        wp_send_json_success(array('message' => 'Rule order updated successfully'));
    }

    /**
     * Update the start_date and end_date schedule fields on a single rule.
     *
     * @since  1.1.0
     * @return void Sends a JSON response and exits.
     */
    public function ajax_update_rule_schedule() {
        check_ajax_referer('wccg_pricing_rules_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $rule_id = isset($_POST['rule_id']) ? intval($_POST['rule_id']) : 0;
        if (!$rule_id) {
            wp_send_json_error(array('message' => 'Invalid rule ID'));
        }

        global $wpdb;
        $table       = $wpdb->prefix . 'pricing_rules';
        $rule_exists = $wpdb->get_var($wpdb->prepare("SELECT rule_id FROM {$table} WHERE rule_id = %d", $rule_id));
        if (!$rule_exists) {
            wp_send_json_error(array('message' => 'Rule not found'));
        }

        $start_date = !empty($_POST['start_date']) ? $this->writer->convert_to_utc($_POST['start_date']) : null;
        $end_date   = !empty($_POST['end_date']) ? $this->writer->convert_to_utc($_POST['end_date']) : null;
        if ($start_date && $end_date && $end_date <= $start_date) {
            wp_send_json_error(array('message' => 'End date must be after start date'));
        }

        $result = $wpdb->update($table, array('start_date' => $start_date, 'end_date' => $end_date), array('rule_id' => $rule_id), array('%s', '%s'), array('%d'));
        if ($result === false) {
            wp_send_json_error(array('message' => 'Database error: ' . $wpdb->last_error));
        }

        $schedule_data = WCCG_Admin_Pricing_Rules_View_Helper::build_schedule_data((object) array('start_date' => $start_date, 'end_date' => $end_date, 'is_active' => 1));
        wp_send_json_success(array(
            'message'              => 'Schedule updated successfully',
            'schedule_status'      => $schedule_data['status'],
            'schedule_badge_html'  => $schedule_data['badge_html'],
            'schedule_display_html' => $schedule_data['display_html']
        ));
    }

    /**
     * Fetch a single rule's data for population of the edit modal.
     *
     * @since  1.1.0
     * @return void Sends a JSON response and exits.
     */
    public function ajax_get_rule_data() {
        check_ajax_referer('wccg_pricing_rules_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $rule_id = isset($_POST['rule_id']) ? intval($_POST['rule_id']) : 0;
        if (!$rule_id) {
            wp_send_json_error(array('message' => 'Invalid rule ID'));
        }

        global $wpdb;
        $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pricing_rules WHERE rule_id = %d", $rule_id));
        if (!$rule) {
            wp_send_json_error(array('message' => 'Rule not found'));
        }

        $product_ids  = $wpdb->get_col($wpdb->prepare("SELECT product_id FROM {$wpdb->prefix}rule_products WHERE rule_id = %d", $rule_id));
        $category_ids = $wpdb->get_col($wpdb->prepare("SELECT category_id FROM {$wpdb->prefix}rule_categories WHERE rule_id = %d", $rule_id));
        wp_send_json_success(array('rule' => $rule, 'product_ids' => array_map('intval', $product_ids), 'category_ids' => array_map('intval', $category_ids)));
    }

    /**
     * Update a pricing rule's core fields and associations via the edit modal.
     *
     * @since  1.1.0
     * @return void Sends a JSON response and exits.
     */
    public function ajax_update_pricing_rule() {
        check_ajax_referer('wccg_pricing_rules_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $rule_id = isset($_POST['rule_id']) ? intval($_POST['rule_id']) : 0;
        if (!$rule_id) {
            wp_send_json_error(array('message' => 'Invalid rule ID'));
        }

        $group_id       = $this->utils->sanitize_input($_POST['group_id'], 'group_id');
        $discount_type  = $this->utils->sanitize_input($_POST['discount_type'], 'discount_type');
        $discount_value = $this->utils->sanitize_input($_POST['discount_value'], 'price');
        if ($group_id === 0) {
            wp_send_json_error(array('message' => 'Invalid customer group selected.'));
        }

        $validation_result = $this->utils->validate_pricing_input($discount_type, $discount_value);
        if (!$validation_result['valid']) {
            wp_send_json_error(array('message' => $validation_result['message']));
        }

        $product_ids  = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? array_filter(array_map('intval', $_POST['product_ids'])) : array();
        $category_ids = isset($_POST['category_ids']) && is_array($_POST['category_ids']) ? array_filter(array_map('intval', $_POST['category_ids'])) : array();
        $result       = $this->writer->update_pricing_rule($rule_id, $group_id, $discount_type, $discount_value, $product_ids, $category_ids);
        if (!$result) {
            wp_send_json_error(array('message' => 'Error occurred while updating pricing rule.'));
        }

        $group_name     = WCCG_Admin_Pricing_Rules_View_Helper::get_group_name_by_id($group_id);
        $product_names  = array();
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product_names[] = $product->get_name();
            }
        }

        $category_names = array();
        foreach ($category_ids as $category_id) {
            $category = get_term($category_id, 'product_cat');
            if ($category && !is_wp_error($category)) {
                $category_names[] = $category->name;
            }
        }

        wp_send_json_success(array(
            'message'          => 'Pricing rule updated successfully.',
            'group_name'       => $group_name,
            'discount_type'    => ucfirst($discount_type),
            'discount_type_raw' => $discount_type,
            'discount_value'   => $discount_type === 'percentage' ? $discount_value . '%' : get_woocommerce_currency_symbol() . $discount_value,
            'product_names'    => $product_names,
            'category_names'   => $category_names,
            'product_ids'      => $product_ids,
            'category_ids'     => $category_ids
        ));
    }
}
