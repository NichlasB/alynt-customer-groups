<?php
/**
 * Pricing Rules admin coordinator: page rendering and AJAX hook registration.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Instantiates the page and AJAX sub-classes and exposes the display_page entry point.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_Pricing_Rules {
    private static $instance = null;
    private $page;
    private $ajax;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Admin_Pricing_Rules
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->page = WCCG_Admin_Pricing_Rules_Page::instance();
        $this->ajax = WCCG_Admin_Pricing_Rules_Ajax::instance();
        $this->ajax->register_hooks();
    }

    /**
     * Delegate script enqueueing to the page sub-class.
     *
     * @since  1.0.0
     * @param  string $hook The current admin page hook suffix.
     * @return void
     */
    public function enqueue_scripts($hook) {
        $this->page->enqueue_scripts($hook);
    }

    /**
     * Render the Pricing Rules admin page.
     *
     * @since  1.0.0
     * @return void
     */
    public function display_page() {
        $this->page->display_page();
    }
}
