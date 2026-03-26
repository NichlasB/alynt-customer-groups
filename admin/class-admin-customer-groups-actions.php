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
	private static $instance = null;
	private $db;
	private $utils;
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

		$action = isset( $_POST['action'] ) ? $this->utils->sanitize_input( wp_unslash( $_POST['action'] ) ) : '';

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

	private function handle_add_group() {
		check_admin_referer( 'wccg_customer_groups_action', 'wccg_customer_groups_nonce' );

		$group_name        = isset( $_POST['group_name'] ) ? $this->utils->sanitize_input( wp_unslash( $_POST['group_name'] ) ) : '';
		$group_description = isset( $_POST['group_description'] ) ? $this->utils->sanitize_input( wp_unslash( $_POST['group_description'] ), 'textarea' ) : '';

		if ( empty( $group_name ) ) {
			$this->add_admin_notice( 'error', __( 'Enter a group name and try again.', 'alynt-customer-groups' ) );
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

	private function handle_delete_group() {
		check_admin_referer( 'wccg_customer_groups_action', 'wccg_customer_groups_nonce' );

		$group_id = isset( $_POST['group_id'] ) ? $this->utils->sanitize_input( wp_unslash( $_POST['group_id'] ), 'group_id' ) : 0;
		if ( ! $group_id ) {
			$this->add_admin_notice( 'error', __( 'Invalid group selected. Please refresh the page and try again.', 'alynt-customer-groups' ) );
			return;
		}

		$default_group_id = get_option( 'wccg_default_group_id', 0 );
		if ( (int) $default_group_id === (int) $group_id ) {
			$this->add_admin_notice( 'error', __( 'Cannot delete the default group for ungrouped customers. Please set a different default group first.', 'alynt-customer-groups' ) );
			return;
		}

		$result = $this->db->transaction(
			function () use ( $group_id ) {
				global $wpdb;

				$this->db->delete_group_pricing_rules( $group_id );
				$wpdb->delete( $wpdb->prefix . 'user_groups', array( 'group_id' => $group_id ), array( '%d' ) );

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

	private function handle_set_default_group() {
		check_admin_referer( 'wccg_customer_groups_action', 'wccg_customer_groups_nonce' );

		$group_id     = isset( $_POST['default_group_id'] ) ? $this->utils->sanitize_input( wp_unslash( $_POST['default_group_id'] ), 'int' ) : 0;
		$custom_title = isset( $_POST['custom_title'] ) ? $this->utils->sanitize_input( wp_unslash( $_POST['custom_title'] ) ) : '';

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

	private function add_admin_notice( $type, $message ) {
		add_settings_error(
			'wccg_customer_groups',
			'wccg_notice',
			$message,
			$type
		);
	}

	private function handle_invalid_request() {
		$this->persist_notice(
			'error',
			__( 'Your security token expired or is invalid. Reload the page and try again.', 'alynt-customer-groups' )
		);
		$this->redirect_back();
	}

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

	private function redirect_back() {
		$redirect_url = wp_get_referer();

		if ( ! $redirect_url ) {
			$redirect_url = admin_url( 'admin.php?page=wccg_customer_groups' );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
