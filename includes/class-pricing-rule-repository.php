<?php
/**
 * Read operations for pricing rules.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Queries the pricing_rules, rule_products, and rule_categories tables to resolve
 * applicable discount rules for a given product and user.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Pricing_Rule_Repository {
    private static $instance = null;
    private $db;
    private $user_groups;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Pricing_Rule_Repository
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->db = WCCG_Database::instance();
        $this->user_groups = WCCG_User_Group_Repository::instance();
    }

    /**
     * Get the applicable pricing rule for a product and user.
     *
     * Resolves the user's group (falling back to the default group if set), then
     * returns a product-specific rule if one exists, otherwise the best category rule.
     *
     * @since  1.0.0
     * @param  int $product_id WooCommerce product or variation ID.
     * @param  int $user_id    WordPress user ID (0 for guest).
     * @return object|null stdClass rule row, or null if no active rule applies.
     */
    public function get_pricing_rule_for_product($product_id, $user_id) {
        $group_id = $this->user_groups->get_user_group($user_id);

        if (!$group_id) {
            $default_group_id = get_option('wccg_default_group_id', 0);
            if ($default_group_id) {
                $group_id = $default_group_id;
            } else {
                return null;
            }
        }

        $product_rule = $this->get_product_specific_rule($product_id, $group_id);
        if ($product_rule) {
            return $product_rule;
        }

        return $this->get_best_category_rule($product_id, $group_id);
    }

    /**
     * Delete all pricing rules and their product/category associations for a group.
     *
     * @since  1.0.0
     * @param  int $group_id Customer group ID.
     * @return bool True on success, false on transaction failure.
     */
    public function delete_group_pricing_rules($group_id) {
        return $this->db->transaction(function() use ($group_id) {
            global $wpdb;

            $rules = $wpdb->get_col($wpdb->prepare(
                "SELECT rule_id FROM {$this->db->get_table_name('pricing_rules')} WHERE group_id = %d",
                $group_id
            ));

            foreach ($rules as $rule_id) {
                $wpdb->delete($this->db->get_table_name('rule_products'), array('rule_id' => $rule_id), array('%d'));
                $wpdb->delete($this->db->get_table_name('rule_categories'), array('rule_id' => $rule_id), array('%d'));
            }

            return $wpdb->delete($this->db->get_table_name('pricing_rules'), array('group_id' => $group_id), array('%d'));
        });
    }

    /**
     * Get all product category IDs (including ancestor categories) for a product.
     *
     * For variation products, uses the parent product's categories.
     *
     * @since  1.0.0
     * @param  int $product_id WooCommerce product ID.
     * @return int[] Unique array of category term IDs.
     */
    public function get_all_product_categories($product_id) {
        $category_ids = array();
        $product = wc_get_product($product_id);

        if ($product && $product->is_type('variation')) {
            $product_id = $product->get_parent_id();
        }

        $terms = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'all'));
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {
                $category_ids[] = $term->term_id;
                $ancestors = get_ancestors($term->term_id, 'product_cat', 'taxonomy');
                if (!empty($ancestors)) {
                    $category_ids = array_merge($category_ids, $ancestors);
                }
            }
        }

        return array_unique($category_ids);
    }

    private function get_product_specific_rule($product_id, $group_id) {
        global $wpdb;

        $product_ids = array($product_id);
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                $product_ids[] = $parent_id;
            }
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $query_args = array_merge(array($group_id), $product_ids);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT pr.*
            FROM {$this->db->get_table_name('pricing_rules')} pr
            JOIN {$this->db->get_table_name('rule_products')} rp ON pr.rule_id = rp.rule_id
            WHERE pr.group_id = %d AND rp.product_id IN ($placeholders) AND pr.is_active = 1
            AND (pr.start_date IS NULL OR pr.start_date <= UTC_TIMESTAMP())
            AND (pr.end_date IS NULL OR pr.end_date >= UTC_TIMESTAMP())
            ORDER BY FIELD(rp.product_id, " . implode(',', $product_ids) . ")
            LIMIT 1",
            $query_args
        ));
    }

    private function get_best_category_rule($product_id, $group_id) {
        $category_ids = $this->get_all_product_categories($product_id);
        if (empty($category_ids)) {
            return null;
        }

        $category_rules = $this->get_category_rules($category_ids, $group_id);
        if (empty($category_rules)) {
            return null;
        }

        return $this->determine_best_rule($category_rules);
    }

    private function get_category_rules($category_ids, $group_id) {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $query = $wpdb->prepare(
            "SELECT pr.*, rc.category_id
            FROM {$this->db->get_table_name('pricing_rules')} pr
            JOIN {$this->db->get_table_name('rule_categories')} rc ON pr.rule_id = rc.rule_id
            WHERE pr.group_id = %d AND rc.category_id IN ($placeholders) AND pr.is_active = 1
            AND (pr.start_date IS NULL OR pr.start_date <= UTC_TIMESTAMP())
            AND (pr.end_date IS NULL OR pr.end_date >= UTC_TIMESTAMP())
            ORDER BY pr.created_at DESC",
            array_merge(array($group_id), $category_ids)
        );

        return $wpdb->get_results($query);
    }

    private function determine_best_rule($rules) {
        $best_rule = null;

        foreach ($rules as $rule) {
            if (!$best_rule) {
                $best_rule = $rule;
                continue;
            }

            if ($this->compare_discount_rules($rule, $best_rule) > 0) {
                $best_rule = $rule;
            }
        }

        return $best_rule;
    }

    private function compare_discount_rules($rule1, $rule2) {
        // Fixed discounts take precedence over percentage discounts of equal value.
        if ($rule1->discount_type !== $rule2->discount_type) {
            return $rule1->discount_type === 'fixed' ? 1 : -1;
        }

        if ($rule1->discount_value === $rule2->discount_value) {
            return strtotime($rule1->created_at) > strtotime($rule2->created_at) ? 1 : -1;
        }

        return $rule1->discount_value > $rule2->discount_value ? 1 : -1;
    }
}
