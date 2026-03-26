<?php
/**
 * Handles plugin deactivation.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Clears scheduled cron events on plugin deactivation.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Deactivator {

    /**
     * Remove all scheduled cron hooks registered by the plugin.
     *
     * @since  1.0.0
     * @return void
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( 'wccg_cleanup_cron' );
        wp_clear_scheduled_hook( 'wccg_check_expired_rules' );
    }
}
