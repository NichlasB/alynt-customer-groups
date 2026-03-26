<?php
/**
 * Pricing Rules admin page rendering and script enqueueing.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the Pricing Rules admin page and enqueues page-specific scripts.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_Pricing_Rules_Page {
    private static $instance = null;
    private $utils;
    private $writer;
    private $actions;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Admin_Pricing_Rules_Page
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->utils   = WCCG_Utilities::instance();
        $this->writer  = WCCG_Pricing_Rule_Write_Service::instance();
        $this->actions = WCCG_Admin_Pricing_Rules_Actions::instance();

        /**
         * Fires when admin scripts and styles should be enqueued.
         *
         * @since 1.0.0
         *
         * @param string $hook_suffix The current admin page hook suffix.
         */
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Enqueue Pricing Rules page scripts and localize the AJAX nonce.
     *
     * @since  1.0.0
     * @param  string $hook The current admin page hook suffix.
     * @return void
     */
    public function enqueue_scripts($hook) {
        if ('customer-groups_page_wccg_pricing_rules' !== $hook) {
            return;
        }

        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_style('dashicons');
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('wccg-admin-script');
        wp_localize_script('wccg-admin-script', 'wccg_pricing_rules', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wccg_pricing_rules_ajax'),
            'strings'  => array(
                'search_products_placeholder' => __('Search and select products...', 'alynt-customer-groups'),
                'search_categories_placeholder' => __('Search and select categories...', 'alynt-customer-groups'),
                'end_date_after_start' => __('End date must be after start date.', 'alynt-customer-groups'),
                'failed_update_rule_order' => __('Failed to update rule order:', 'alynt-customer-groups'),
                'error_update_rule_order' => __('An error occurred while updating the rule order.', 'alynt-customer-groups'),
                'saving' => __('Saving...', 'alynt-customer-groups'),
                'updating_schedule' => __('Updating schedule...', 'alynt-customer-groups'),
                'updating_rule' => __('Updating rule...', 'alynt-customer-groups'),
                'loading_rule_data' => __('Loading rule data...', 'alynt-customer-groups'),
                'failed_load_rule_data' => __('Failed to load rule data.', 'alynt-customer-groups'),
                'save_schedule' => __('Save Schedule', 'alynt-customer-groups'),
                'save_changes' => __('Save Changes', 'alynt-customer-groups'),
                'percentage_hint' => __('Enter a percentage between 0 and 100.', 'alynt-customer-groups'),
                'fixed_hint' => __('Enter the fixed discount amount.', 'alynt-customer-groups'),
                'select_customer_group' => __('Please select a customer group.', 'alynt-customer-groups'),
                'enter_valid_discount' => __('Please enter a valid discount value.', 'alynt-customer-groups'),
                'percentage_exceed' => __('Percentage discount cannot exceed 100%.', 'alynt-customer-groups'),
                'fixed_precedence_short' => __('Fixed discounts take precedence', 'alynt-customer-groups'),
                'product_rule' => __('Product Rule', 'alynt-customer-groups'),
                'category_rule' => __('Category Rule', 'alynt-customer-groups'),
                'rule_update_error' => __('An error occurred while updating the rule.', 'alynt-customer-groups'),
                'schedule_update_error' => __('An error occurred while updating the schedule.', 'alynt-customer-groups'),
                'error_prefix' => __('Error:', 'alynt-customer-groups'),
            ),
        ));
        wp_add_inline_style('woocommerce_admin_styles', '.woocommerce select:not(.select2-hidden-accessible) { display: block !important; visibility: visible !important; } .select2-container { display: none !important; }');
    }

    /**
     * Handle any pending form submissions, fetch all required data, and render the page.
     *
     * @since  1.0.0
     * @return void
     */
    public function display_page() {
        global $wpdb;

        $this->utils->verify_admin_access();
        $this->actions->handle_form_submission();

        $groups               = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}customer_groups ORDER BY group_name ASC");
        $all_products         = wc_get_products(array('limit' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        $all_categories       = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
        $pricing_rules_view   = WCCG_Admin_Pricing_Rules_View_Helper::build_pricing_rules_view($this->get_pricing_rules());
        $conflicts            = $this->writer->get_rule_conflicts();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pricing Rules', 'alynt-customer-groups'); ?></h1>
            <?php include WCCG_PATH . 'admin/views/html-pricing-rules-info-box.php'; ?>
            <?php settings_errors('wccg_pricing_rules'); ?>
            <form method="post">
                <?php wp_nonce_field('wccg_pricing_rules_action', 'wccg_pricing_rules_nonce'); ?>
                <input type="hidden" name="action" value="save_rule">
                <?php include WCCG_PATH . 'admin/views/html-pricing-rules-form.php'; ?>
            </form>
            <h2><?php esc_html_e('Existing Pricing Rules', 'alynt-customer-groups'); ?></h2>
            <?php include WCCG_PATH . 'admin/views/html-pricing-rules-list.php'; ?>
        </div>
        <?php
    }

    private function get_pricing_rules() {
        global $wpdb;

        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'sort_order'",
            DB_NAME,
            $wpdb->prefix . 'pricing_rules'
        ));
        $order_by = !empty($column_exists) ? 'pr.sort_order ASC, pr.rule_id ASC' : 'pr.rule_id ASC';

        return $wpdb->get_results(
            "SELECT pr.*, GROUP_CONCAT(DISTINCT rp.product_id) as product_ids, GROUP_CONCAT(DISTINCT rc.category_id) as category_ids
            FROM {$wpdb->prefix}pricing_rules pr
            LEFT JOIN {$wpdb->prefix}rule_products rp ON pr.rule_id = rp.rule_id
            LEFT JOIN {$wpdb->prefix}rule_categories rc ON pr.rule_id = rc.rule_id
            GROUP BY pr.rule_id
            ORDER BY {$order_by}",
            OBJECT_K
        );
    }
}

