<?php
/**
 * Security helpers: nonce verification, output escaping, and capability checks.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wraps WordPress security functions for consistent use across the plugin.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Security_Helper {
    private static $instance = null;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Security_Helper
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Verify a WordPress nonce from $_REQUEST; call wp_die() on failure.
     *
     * @since  1.0.0
     * @param  string $nonce_name  The nonce action name to verify against.
     * @param  string $request_key The $_REQUEST key that holds the nonce value. Default '_wpnonce'.
     * @return bool True on success.
     */
    public function verify_nonce($nonce_name, $request_key = '_wpnonce') {
        if (!isset($_REQUEST[$request_key])) {
            wp_die(esc_html__('Security check failed: nonce not set.', 'alynt-customer-groups'));
        }

        if (!wp_verify_nonce($_REQUEST[$request_key], $nonce_name)) {
            wp_die(esc_html__('Security check failed: invalid nonce.', 'alynt-customer-groups'));
        }

        return true;
    }

    /**
     * Escape a value for safe output using the appropriate WordPress escaping function.
     *
     * @since  1.0.0
     * @param  mixed  $data The value to escape.
     * @param  string $type Escape context: 'html' (wp_kses_post), 'url' (esc_url),
     *                      'attr' (esc_attr), 'textarea' (esc_textarea), or 'text' (esc_html).
     * @return string Escaped string.
     */
    public function escape_output($data, $type = 'text') {
        switch ($type) {
            case 'html':
                return wp_kses_post($data);
            case 'url':
                return esc_url($data);
            case 'attr':
                return esc_attr($data);
            case 'textarea':
                return esc_textarea($data);
            default:
                return esc_html($data);
        }
    }

    /**
     * Verify the current user has manage_woocommerce; call wp_die() on failure.
     *
     * @since  1.0.0
     * @return void
     */
    public function verify_admin_access() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'alynt-customer-groups'));
        }
    }
}

