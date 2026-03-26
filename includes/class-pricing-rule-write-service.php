<?php
/**
 * Write operations for pricing rules.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Creates and updates pricing rules and their product/category associations.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Pricing_Rule_Write_Service {
    private static $instance = null;
    private $db;
    private $utils;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Pricing_Rule_Write_Service
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
    }

    /**
     * Convert a local datetime string (from an HTML datetime-local input) to UTC.
     *
     * @since  1.1.0
     * @param  string $datetime_local Datetime string in site timezone (e.g. '2026-03-01T14:00').
     * @return string UTC datetime string formatted as 'Y-m-d H:i:s'.
     */
    public function convert_to_utc($datetime_local) {
        $datetime_formatted = str_replace('T', ' ', $datetime_local) . ':00';
        $dt = new DateTime($datetime_formatted, wp_timezone());
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Update an existing pricing rule's core fields and replace its product/category associations.
     *
     * @since  1.0.0
     * @param  int    $rule_id       The ID of the rule to update.
     * @param  int    $group_id      Customer group ID.
     * @param  string $discount_type 'percentage' or 'fixed'.
     * @param  float  $discount_value Discount amount.
     * @param  int[]  $product_ids   Product IDs to associate with this rule.
     * @param  int[]  $category_ids  Category IDs to associate with this rule.
     * @return bool True on success, false on transaction failure.
     */
    public function update_pricing_rule($rule_id, $group_id, $discount_type, $discount_value, $product_ids, $category_ids) {
        return $this->db->transaction(function() use ($rule_id, $group_id, $discount_type, $discount_value, $product_ids, $category_ids) {
            global $wpdb;

            $result = $wpdb->update(
                $wpdb->prefix . 'pricing_rules',
                array(
                    'group_id'       => $group_id,
                    'discount_type'  => $discount_type,
                    'discount_value' => $discount_value
                ),
                array('rule_id' => $rule_id),
                array('%d', '%s', '%f'),
                array('%d')
            );

            if ($result === false) {
                throw new Exception('Failed to update pricing rule');
            }

            $wpdb->delete($wpdb->prefix . 'rule_products', array('rule_id' => $rule_id), array('%d'));
            $wpdb->delete($wpdb->prefix . 'rule_categories', array('rule_id' => $rule_id), array('%d'));

            foreach ($product_ids as $product_id) {
                $result = $wpdb->insert(
                    $wpdb->prefix . 'rule_products',
                    array(
                        'rule_id'    => $rule_id,
                        'product_id' => $product_id,
                    ),
                    array('%d', '%d')
                );

                if ($result === false) {
                    throw new Exception('Failed to insert product association');
                }
            }

            foreach ($category_ids as $category_id) {
                $result = $wpdb->insert(
                    $wpdb->prefix . 'rule_categories',
                    array(
                        'rule_id'     => $rule_id,
                        'category_id' => $category_id,
                    ),
                    array('%d', '%d')
                );

                if ($result === false) {
                    throw new Exception('Failed to insert category association');
                }
            }

            return true;
        });
    }

    /**
     * Insert a new pricing rule and associate it with products and/or categories.
     *
     * @since  1.0.0
     * @param  int         $group_id      Customer group ID.
     * @param  string      $discount_type 'percentage' or 'fixed'.
     * @param  float       $discount_value Discount amount.
     * @param  int[]       $product_ids   Product IDs to associate with this rule.
     * @param  int[]       $category_ids  Category IDs to associate with this rule.
     * @param  string|null $start_date    UTC start datetime, or null for no start constraint.
     * @param  string|null $end_date      UTC end datetime, or null for no end constraint.
     * @return bool True on success, false on transaction failure.
     */
    public function save_pricing_rule($group_id, $discount_type, $discount_value, $product_ids, $category_ids, $start_date = null, $end_date = null) {
        return $this->db->transaction(function() use ($group_id, $discount_type, $discount_value, $product_ids, $category_ids, $start_date, $end_date) {
            global $wpdb;

            $column_exists = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'sort_order'",
                DB_NAME,
                $wpdb->prefix . 'pricing_rules'
            ));

            $insert_data = array(
                'group_id'       => $group_id,
                'discount_type'  => $discount_type,
                'discount_value' => $discount_value,
                'is_active'      => 1
            );
            $insert_format = array('%d', '%s', '%f', '%d');

            if (!empty($column_exists)) {
                $max_sort_order = $wpdb->get_var("SELECT MAX(sort_order) FROM {$wpdb->prefix}pricing_rules");
                $insert_data['sort_order'] = $max_sort_order ? $max_sort_order + 1 : 1;
                $insert_format[] = '%d';
            }

            if ($start_date !== null) {
                $insert_data['start_date'] = $start_date;
                $insert_format[] = '%s';
            }

            if ($end_date !== null) {
                $insert_data['end_date'] = $end_date;
                $insert_format[] = '%s';
            }

            $result = $wpdb->insert($wpdb->prefix . 'pricing_rules', $insert_data, $insert_format);
            if ($result === false) {
                throw new Exception('Failed to insert pricing rule');
            }

            $rule_id = $wpdb->insert_id;
            foreach ($product_ids as $product_id) {
                $result = $wpdb->insert(
                    $wpdb->prefix . 'rule_products',
                    array('rule_id' => $rule_id, 'product_id' => $product_id),
                    array('%d', '%d')
                );
                if ($result === false) {
                    throw new Exception('Failed to insert product association');
                }
            }

            foreach ($category_ids as $category_id) {
                $result = $wpdb->insert(
                    $wpdb->prefix . 'rule_categories',
                    array('rule_id' => $rule_id, 'category_id' => $category_id),
                    array('%d', '%d')
                );
                if ($result === false) {
                    throw new Exception('Failed to insert category association');
                }
            }

            return true;
        });
    }

    /**
     * Return a list of human-readable conflict messages for duplicate product/category rules.
     *
     * @since  1.0.0
     * @return string[] Array of conflict description strings.
     */
    public function get_rule_conflicts() {
        global $wpdb;

        $conflicts = array();
        $product_conflicts = $wpdb->get_results(
            "SELECT p.product_id, pr.group_id, COUNT(*) as rule_count, g.group_name
            FROM {$wpdb->prefix}rule_products p
            JOIN {$wpdb->prefix}pricing_rules pr ON p.rule_id = pr.rule_id
            JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
            GROUP BY p.product_id, pr.group_id
            HAVING rule_count > 1"
        );

        foreach ($product_conflicts as $conflict) {
            $product = wc_get_product($conflict->product_id);
            if ($product) {
                $conflicts[] = sprintf(
                    __('Product "%s" has multiple rules for group "%s"', 'alynt-customer-groups'),
                    $product->get_name(),
                    $conflict->group_name
                );
            }
        }

        $category_conflicts = $wpdb->get_results(
            "SELECT rc.category_id, pr.group_id, COUNT(DISTINCT pr.rule_id) as rule_count, g.group_name, t.name as category_name
            FROM {$wpdb->prefix}rule_categories rc
            JOIN {$wpdb->prefix}pricing_rules pr ON rc.rule_id = pr.rule_id
            JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
            JOIN {$wpdb->prefix}terms t ON rc.category_id = t.term_id
            GROUP BY rc.category_id, pr.group_id
            HAVING rule_count > 1"
        );

        foreach ($category_conflicts as $conflict) {
            $conflicts[] = sprintf(
                __('Category "%s" has multiple rules for group "%s"', 'alynt-customer-groups'),
                $conflict->category_name,
                $conflict->group_name
            );
        }

        return $conflicts;
    }
}

