<?php
/**
 * Input validation and sanitization.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates pricing input and sanitizes arbitrary input values according to a named type.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Input_Sanitizer {
    private static $instance = null;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Input_Sanitizer
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Validate a discount type and value combination.
     *
     * Rules: type must be 'percentage' or 'fixed'; value must be numeric;
     * percentage must be 0–100; fixed must be 0–10000.
     *
     * @since  1.0.0
     * @param  string $discount_type  'percentage' or 'fixed'.
     * @param  mixed  $discount_value The discount amount to validate.
     * @return array { @type bool $valid, @type string $message (only present on failure) }
     */
    public function validate_pricing_input($discount_type, $discount_value) {
        if (!in_array($discount_type, array('percentage', 'fixed'), true)) {
            return array(
                'valid'   => false,
                'message' => 'Invalid discount type.'
            );
        }

        if (!is_numeric($discount_value)) {
            return array(
                'valid'   => false,
                'message' => 'Discount value must be a number.'
            );
        }

        if ($discount_type === 'percentage' && ($discount_value < 0 || $discount_value > 100)) {
            return array(
                'valid'   => false,
                'message' => 'Percentage discount must be between 0 and 100.'
            );
        }

        if ($discount_type === 'fixed') {
            if ($discount_value < 0) {
                return array(
                    'valid'   => false,
                    'message' => 'Fixed discount cannot be negative.'
                );
            }

            $max_fixed_discount = 10000;
            if ($discount_value > $max_fixed_discount) {
                return array(
                    'valid'   => false,
                    'message' => sprintf('Fixed discount cannot exceed %s.', wc_price($max_fixed_discount))
                );
            }
        }

        return array('valid' => true);
    }

    /**
     * Sanitize an input value according to the specified type.
     *
     * @since  1.0.0
     * @param  mixed  $data The raw input value.
     * @param  string $type Sanitization type: 'int', 'float', 'price', 'email', 'url',
     *                      'textarea', 'array', 'group_id', 'discount_type', or 'text' (default).
     * @param  array  $args Optional additional arguments (reserved for future use).
     * @return mixed Sanitized value. Returns '' for null input.
     */
    public function sanitize_input($data, $type = 'text', $args = array()) {
        if (is_null($data)) {
            return '';
        }

        switch ($type) {
            case 'int':
                return intval($data);
            case 'float':
                return (float) filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            case 'price':
                $price = (float) filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                return round($price, wc_get_price_decimals());
            case 'email':
                return sanitize_email($data);
            case 'url':
                return esc_url_raw($data);
            case 'textarea':
                return sanitize_textarea_field($data);
            case 'array':
                if (!is_array($data)) {
                    return array();
                }

                return array_map('sanitize_text_field', $data);
            case 'group_id':
                return $this->sanitize_group_id($data);
            case 'discount_type':
                $allowed_types = array('percentage', 'fixed');
                $sanitized = sanitize_text_field($data);
                return in_array($sanitized, $allowed_types, true) ? $sanitized : '';
            default:
                return sanitize_text_field($data);
        }
    }

    private function sanitize_group_id($data) {
        global $wpdb;

        $group_id = intval($data);
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
            $group_id
        ));

        return $exists ? $group_id : 0;
    }
}
