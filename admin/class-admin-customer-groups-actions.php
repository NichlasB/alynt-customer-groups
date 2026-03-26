<?php
/**
 * Form action handlers for customer group create, delete, and default-group operations.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes POST submissions on the Customer Groups admin page.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_Customer_Groups_Actions {
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Admin_Customer_Groups_Actions|null
	 */
	private static $instance = null;

	/**
	 * Database facade.
	 *
	 * @var WCCG_Database
	 */
	private $db;

	/**
	 * Shared utility helper.
	 *
	 * @var WCCG_Utilities
	 */
	private $utils;

	/**
	 * Option key used to persist redirect-safe notices.
	 *
	 * @var string
	 */
	private $notice_option_key = 'wccg_customer_groups_action_notice';

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Admin_Customer_Groups_Actions
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize customer group action dependencies.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function __construct() {
		$this->db    = WCCG_Database::instance();
		$this->utils = WCCG_Utilities::instance();
	}

	/**
	 * Route an incoming POST request to the appropriate action handler.
	 *
	 * Verifies the wccg_customer_groups_action nonce before dispatching.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function handle_form_submission() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}

		if ( ! isset( $_POST['wccg_customer_groups_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wccg_customer_groups_nonce'] ) ), 'wccg_customer_groups_action' ) ) {
			$this->handle_invalid_request();
			return;
		}

		$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';

		switch ( $action ) {
			case 'add_group':
				$this->handle_add_group();
				break;
			case 'delete_group':
				$this->handle_delete_group();
				break;
			case 'set_default_group':
				$this->handle_set_default_group();
				break;
		}
	}

	/**
	 * Validate and create a submitted customer group.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function handle_add_group() {
		check_admin_referer( 'wccg_customer_groups_action', 'wccg_customer_groups_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->add_admin_notice( 'error', __( 'You do not have permission to add customer groups.', 'alynt-customer-groups' ) );
			return;
		}

		$group_name        = isset( $_POST['group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['group_name'] ) ) : '';
		$group_description = isset( $_POST['group_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['group_description'] ) ) : '';

		if ( empty( $group_name ) ) {
			$this->add_admin_notice( 'error', __( 'Enter a group name and try again.', 'alynt-customer-groups' ) );
			return;
		}

		$group_name_length = function_exists( 'mb_strlen' ) ? mb_strlen( $group_name ) : strlen( $group_name );
		if ( $group_name_length > 255 ) {
			$this->add_admin_notice( 'error', __( 'Group name cannot exceed 255 characters.', 'alynt-customer-groups' ) );
			return;
		}

		if ( $this->is_duplicate_submission( 'add_group', array( $group_name, $group_description ) ) ) {
			$this->add_admin_notice( 'warning', __( 'This group submission was already processed. Refresh the page before trying again.', 'alynt-customer-groups' ) );
			return;
		}

		$result = $this->db->transaction(
			function () use ( $group_name, $group_description ) {
				global $wpdb;

				return $wpdb->insert(
					$wpdb->prefix . 'customer_groups',
					array(
						'group_name'        => $group_name,
						'group_description' => $group_description,
					),
					array( '%s', '%s' )
				);
			}
		);

		if ( $result ) {
			$this->add_admin_notice( 'success', __( 'Customer group added successfully.', 'alynt-customer-groups' ) );
			return;
		}

		$this->utils->log_error(
			'Customer group creation failed.',
			array(
				'group_name' => $group_name,
			)
		);
		$this->add_admin_notice( 'error', __( 'Could not add the customer group. Please try again. If the problem continues, contact support.', 'alynt-customer-groups' ) );
	}

	/**
	 * Validate and delete a submitted customer group.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function handle_delete_group() {
		check_admin_referer( 'wccg_customer_groups_action', 'wccg_customer_groups_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->add_admin_notice( 'error', __( 'You do not have permission to delete customer groups.', 'alynt-customer-groups' ) );
			return;
		}

		$group_id = isset( $_POST['group_id'] ) ? absint( wp_unslash( $_POST['group_id'] ) ) : 0;
		if ( ! $group_id ) {
			$this->add_admin_notice( 'error', __( 'Invalid group selected. Please refresh the page and try again.', 'alynt-customer-groups' ) );
			return;
		}

		global $wpdb;

		$group_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT group_id FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
				$group_id
			)
		);
		if ( ! $group_exists ) {
			$this->add_admin_notice( 'error', __( 'Selected group does not exist.', 'alynt-customer-groups' ) );
			return;
		}

		$default_group_id = get_option( 'wccg_default_group_id', 0 );
		if ( (int) $default_group_id === (int) $group_id ) {
			$this->add_admin_notice( 'error', __( 'Cannot delete the default group for ungrouped customers. Please set a different default group first.', 'alynt-customer-groups' ) );
			return;
		}

		$assigned_user_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}user_groups WHERE group_id = %d",
				$group_id
			)
		);
		$pricing_rule_count  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}pricing_rules WHERE group_id = %d",
				$group_id
			)
		);

		if ( $assigned_user_count > 0 || $pricing_rule_count > 0 ) {
			$dependencies = array();

			if ( $assigned_user_count > 0 ) {
				$dependencies[] = sprintf(
					/* translators: %d: number of user assignments. */
					_n( '%d user assignment', '%d user assignments', $assigned_user_count, 'alynt-customer-groups' ),
					$assigned_user_count
				);
			}

			if ( $pricing_rule_count > 0 ) {
				$dependencies[] = sprintf(
					/* translators: %d: number of pricing rules. */
					_n( '%d pricing rule', '%d pricing rules', $pricing_rule_count, 'alynt-customer-groups' ),
					$pricing_rule_count
				);
			}

			$this->add_admin_notice(
				'error',
				sprintf(
					/* translators: %s is a comma-separated dependency list. */
					__( 'Cannot delete this group because %s still depend on it. Reassign or remove them first.', 'alynt-customer-groups' ),
					implode( __( ' and ', 'alynt-customer-groups' ), $dependencies )
				)
			);
			return;
		}

		$result = $this->db->transaction(
			function () use ( $group_id ) {
				global $wpdb;

				return $wpdb->delete(
					$wpdb->prefix . 'customer_groups',
					array( 'group_id' => $group_id ),
					array( '%d' )
				);
			}
		);

		if ( $result ) {
			$this->add_admin_notice( 'success', __( 'Customer group and associated data deleted successfully.', 'alynt-customer-groups' ) );
			return;
		}

		$this->utils->log_error(
			'Customer group deletion failed.',
			array(
				'group_id' => $group_id,
			)
		);
		$this->add_admin_notice( 'error', __( 'Could not delete the customer group. Please try again. If the problem continues, contact support.', 'alynt-customer-groups' ) );
	}

	/**
	 * Validate and save default group settings.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function handle_set_default_group() {
		check_admin_referer( 'wccg_customer_groups_action', 'wccg_customer_groups_nonce' );

		$group_id     = isset( $_POST['default_group_id'] ) ? absint( wp_unslash( $_POST['default_group_id'] ) ) : 0;
		$custom_title = isset( $_POST['custom_title'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_title'] ) ) : '';

		if ( $group_id < 0 ) {
			$this->add_admin_notice( 'error', __( 'Invalid group selected. Please refresh the page and try again.', 'alynt-customer-groups' ) );
			return;
		}

		if ( $group_id > 0 ) {
			global $wpdb;

			$group_exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT group_id FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
					$group_id
				)
			);

			if ( ! $group_exists ) {
				$this->add_admin_notice( 'error', __( 'Selected group does not exist.', 'alynt-customer-groups' ) );
				return;
			}
		}

		$current_group_id     = (int) get_option( 'wccg_default_group_id', 0 );
		$current_custom_title = (string) get_option( 'wccg_default_group_custom_title', '' );
		$group_updated        = ( $current_group_id === (int) $group_id ) ? true : update_option( 'wccg_default_group_id', $group_id );
		$title_updated        = ( $current_custom_title === (string) $custom_title ) ? true : update_option( 'wccg_default_group_custom_title', $custom_title );

		if ( false === $group_updated || false === $title_updated ) {
			$this->utils->log_error(
				'Default group update failed.',
				array(
					'group_id'     => $group_id,
					'custom_title' => $custom_title,
				)
			);
			$this->add_admin_notice( 'error', __( 'Could not save the default group settings. Please try again.', 'alynt-customer-groups' ) );
			return;
		}

		if ( $group_id > 0 ) {
			$this->add_admin_notice( 'success', __( 'Default group for ungrouped customers updated successfully.', 'alynt-customer-groups' ) );
			return;
		}

		$this->add_admin_notice( 'success', __( 'Default group disabled. Ungrouped customers will see regular prices.', 'alynt-customer-groups' ) );
	}

	/**
	 * Queue an admin notice for the customer groups screen.
	 *
	 * @since  1.0.0
	 * @param  string $type    Notice type accepted by add_settings_error().
	 * @param  string $message Notice text.
	 * @return void
	 */
	private function add_admin_notice( $type, $message ) {
		add_settings_error(
			'wccg_customer_groups',
			'wccg_notice',
			$message,
			$type
		);
	}

	/**
	 * Persist an invalid-request notice and redirect back to the screen.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function handle_invalid_request() {
		$this->persist_notice(
			'error',
			__( 'Your security token expired or is invalid. Reload the page and try again.', 'alynt-customer-groups' )
		);
		$this->redirect_back();
	}

	/**
	 * Persist a notice through redirect-safe option storage.
	 *
	 * @since  1.0.0
	 * @param  string $type    Notice type accepted by add_settings_error().
	 * @param  string $message Notice text.
	 * @return void
	 */
	private function persist_notice( $type, $message ) {
		update_option(
			$this->notice_option_key,
			array(
				'type'    => $type,
				'message' => $message,
			),
			false
		);
	}

	/**
	 * Redirect back to the customer groups screen.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function redirect_back() {
		$redirect_url = wp_get_referer();

		if ( ! $redirect_url ) {
			$redirect_url = admin_url( 'admin.php?page=wccg_customer_groups' );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Detect duplicate submissions within a short time window.
	 *
	 * @since  1.0.0
	 * @param  string $action  Action identifier.
	 * @param  array  $payload Submitted payload values.
	 * @return bool True when the submission was already processed.
	 */
	private function is_duplicate_submission( $action, $payload ) {
		$user_id = get_current_user_id();
		$key     = 'wccg_submit_' . md5( $action . '|' . $user_id . '|' . wp_json_encode( $payload ) );

		if ( get_transient( $key ) ) {
			return true;
		}

		set_transient( $key, 1, MINUTE_IN_SECONDS );
		return false;
	}
}
