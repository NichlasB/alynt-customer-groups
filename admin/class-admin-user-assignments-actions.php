<?php
/**
 * Form action handlers for user-group assignments, unassignments, and CSV export.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Processes POST submissions on the User Assignments admin page.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_User_Assignments_Actions {
    private static $instance = null;
    private $db;
    private $utils;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Admin_User_Assignments_Actions
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
     * Verifies the wccg_user_assignments_action nonce before dispatching.
     *
     * @since  1.0.0
     * @return void
     */
    public function handle_form_submission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!isset($_POST['wccg_user_assignments_nonce']) || !wp_verify_nonce($_POST['wccg_user_assignments_nonce'], 'wccg_user_assignments_action')) {
            wp_die(esc_html__('Security check failed.', 'alynt-customer-groups'));
        }

        if (isset($_POST['export_csv'])) {
            $this->handle_csv_export();
            return;
        }

        if (!isset($_POST['action'])) {
            return;
        }

        switch ($_POST['action']) {
            case 'assign_users':
                $this->handle_user_assignments();
                break;
            case 'unassign_users':
                $this->handle_user_unassignments();
                break;
        }
    }

    private function handle_csv_export() {
        if (!isset($_POST['user_ids']) || empty($_POST['user_ids'])) {
            $this->add_admin_notice('error', __('Please select users to export.', 'alynt-customer-groups'));
            return;
        }

        $user_ids = array_map('intval', $_POST['user_ids']);
        $max_export_users = 1000;
        if (count($user_ids) > $max_export_users) {
            $this->add_admin_notice(
                'error',
                sprintf(
                    /* translators: %d: maximum number of users allowed in a CSV export batch. */
                    __('Maximum of %d users can be exported at once.', 'alynt-customer-groups'),
                    $max_export_users
                )
            );
            return;
        }

        if (ob_get_level()) {
            ob_end_clean();
        }

        remove_all_actions('shutdown');
        $output = null;

        try {
            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=customer-groups-export-' . date('Y-m-d') . '.csv');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new Exception('Failed to open output stream');
            }

            // BOM for Excel UTF-8 compatibility.
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($output, array(
                __('User ID', 'alynt-customer-groups'),
                __('Username', 'alynt-customer-groups'),
                __('Email', 'alynt-customer-groups'),
                __('First Name', 'alynt-customer-groups'),
                __('Last Name', 'alynt-customer-groups'),
                __('Customer Group', 'alynt-customer-groups'),
                __('Registration Date', 'alynt-customer-groups'),
            ));

            foreach ($user_ids as $user_id) {
                $user = get_userdata($user_id);
                if (!$user) {
                    continue;
                }

                fputcsv($output, array(
                    $user->ID,
                    $user->user_login,
                    $user->user_email,
                    $user->first_name,
                    $user->last_name,
                    $this->db->get_user_group_name($user_id) ?: __('Unassigned', 'alynt-customer-groups'),
                    $user->user_registered
                ));
            }

            fclose($output);
            exit();
        } catch (Exception $e) {
            if ($output) {
                fclose($output);
            }

            $this->utils->log_error(
                'CSV Export Error: ' . $e->getMessage(),
                array(
                    'user_ids' => $user_ids,
                    'trace'    => $e->getTraceAsString()
                )
            );
            wp_die(esc_html__('Error generating CSV file. Please try again.', 'alynt-customer-groups'));
        }
    }

    private function handle_user_assignments() {
        if (!isset($_POST['user_ids']) || empty($_POST['user_ids']) || !isset($_POST['group_id'])) {
            $this->add_admin_notice('error', __('Please select users and a group.', 'alynt-customer-groups'));
            return;
        }

        if (!$this->utils->check_rate_limit(get_current_user_id(), 'group_change')) {
            $this->add_admin_notice('error', __('Too many group assignments attempted. Please wait a few minutes and try again.', 'alynt-customer-groups'));
            return;
        }

        $user_ids = array_map('intval', $_POST['user_ids']);
        $group_id = $this->utils->sanitize_input($_POST['group_id'], 'group_id');

        if (empty($user_ids) || empty($group_id)) {
            $this->add_admin_notice('error', __('Invalid user IDs or group ID provided.', 'alynt-customer-groups'));
            return;
        }

        $max_users_per_batch = 100;
        if (count($user_ids) > $max_users_per_batch) {
            $this->add_admin_notice(
                'error',
                sprintf(
                    /* translators: %d: maximum number of users allowed in a group assignment batch. */
                    __('Maximum of %d users can be assigned at once.', 'alynt-customer-groups'),
                    $max_users_per_batch
                )
            );
            return;
        }

        $result = $this->db->bulk_assign_user_groups($user_ids, $group_id);
        if ($result) {
            $this->add_admin_notice('success', __('Users assigned to group successfully.', 'alynt-customer-groups'));
            return;
        }

        $this->add_admin_notice('error', __('Error occurred while assigning users to group.', 'alynt-customer-groups'));
    }

    private function handle_user_unassignments() {
        if (!isset($_POST['user_ids']) || empty($_POST['user_ids'])) {
            $this->add_admin_notice('error', __('Please select users to unassign.', 'alynt-customer-groups'));
            return;
        }

        if (!$this->utils->check_rate_limit(get_current_user_id(), 'group_change')) {
            $this->add_admin_notice('error', __('Too many group changes attempted. Please wait a few minutes and try again.', 'alynt-customer-groups'));
            return;
        }

        $user_ids = array_map('intval', $_POST['user_ids']);
        if (empty($user_ids)) {
            $this->add_admin_notice('error', __('Invalid user IDs provided.', 'alynt-customer-groups'));
            return;
        }

        $max_users_per_batch = 100;
        if (count($user_ids) > $max_users_per_batch) {
            $this->add_admin_notice(
                'error',
                sprintf(
                    /* translators: %d: maximum number of users allowed in an unassignment batch. */
                    __('Maximum of %d users can be unassigned at once.', 'alynt-customer-groups'),
                    $max_users_per_batch
                )
            );
            return;
        }

        $result = $this->db->transaction(function() use ($user_ids) {
            global $wpdb;

            $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            return $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}user_groups WHERE user_id IN ($placeholders)",
                $user_ids
            ));
        });

        if ($result !== false) {
            $this->add_admin_notice('success', __('Users unassigned successfully.', 'alynt-customer-groups'));
            return;
        }

        $this->add_admin_notice('error', __('Error occurred while unassigning users.', 'alynt-customer-groups'));
    }

    private function add_admin_notice($type, $message) {
        add_settings_error(
            'wccg_user_assignments',
            'wccg_notice',
            $message,
            $type
        );
    }
}
