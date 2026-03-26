<?php
/**
 * Form action handlers for pricing rule create and delete operations.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes POST submissions on the Pricing Rules admin page.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_Pricing_Rules_Actions {
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Admin_Pricing_Rules_Actions|null
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
	 * Pricing rule write service.
	 *
	 * @var WCCG_Pricing_Rule_Write_Service
	 */
	private $writer;

	/**
	 * Sanitized form values preserved after validation failures.
	 *
	 * @var array<string,mixed>
	 */
	private $form_values = array();

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Admin_Pricing_Rules_Actions
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the pricing rules action dependencies.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function __construct() {
		$this->db     = WCCG_Database::instance();
		$this->utils  = WCCG_Utilities::instance();
		$this->writer = WCCG_Pricing_Rule_Write_Service::instance();
	}

	/**
	 * Route an incoming POST request to the appropriate action handler.
	 *
	 * Verifies the wccg_pricing_rules_action nonce before dispatching.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function handle_form_submission() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}

		if ( ! isset( $_POST['wccg_pricing_rules_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wccg_pricing_rules_nonce'] ) ), 'wccg_pricing_rules_action' ) ) {
			$this->add_admin_notice( 'error', __( 'Your security token expired or is invalid. Reload the page and try again.', 'alynt-customer-groups' ) );
			return;
		}

		if ( ! isset( $_POST['action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['action'] ) );

		switch ( $action ) {
			case 'save_rule':
				$this->handle_save_rule();
				break;
			case 'delete_rule':
				$this->handle_delete_rule();
				break;
			case 'enable_all_rules':
				$this->handle_bulk_status_change( 1 );
				break;
			case 'disable_all_rules':
				$this->handle_bulk_status_change( 0 );
				break;
			case 'delete_all_rules':
				$this->handle_delete_all_rules();
				break;
		}
	}

	/**
	 * Return the current form values with defaults applied.
	 *
	 * @since  1.0.0
	 * @return array<string,mixed> Form values for the Pricing Rules screen.
	 */
	public function get_form_values() {
		return wp_parse_args(
			$this->form_values,
			array(
				'group_id'       => 0,
				'discount_type'  => 'fixed',
				'discount_value' => '',
				'start_date'     => '',
				'end_date'       => '',
				'product_ids'    => array(),
				'category_ids'   => array(),
			)
		);
	}

	/**
	 * Validate and save a submitted pricing rule.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function handle_save_rule() {
		check_admin_referer( 'wccg_pricing_rules_action', 'wccg_pricing_rules_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->add_admin_notice( 'error', __( 'You do not have permission to save pricing rules.', 'alynt-customer-groups' ) );
			return;
		}

		$this->form_values = $this->extract_form_values();

		if ( $this->form_values['group_id'] === 0 ) {
			$this->add_admin_notice( 'error', __( 'Select a customer group and try again.', 'alynt-customer-groups' ) );
			return;
		}

		$validation_result = $this->utils->validate_pricing_input( $this->form_values['discount_type'], $this->form_values['discount_value'] );
		if ( ! $validation_result['valid'] ) {
			$this->add_admin_notice( 'error', $validation_result['message'] );
			return;
		}

		if ( $this->is_duplicate_submission( 'save_rule', $this->form_values ) ) {
			$this->add_admin_notice( 'warning', __( 'This pricing rule submission was already processed. Refresh the page before trying again.', 'alynt-customer-groups' ) );
			return;
		}

		$start_date = ! empty( $this->form_values['start_date'] ) ? $this->writer->convert_to_utc( $this->form_values['start_date'] ) : null;
		$end_date   = ! empty( $this->form_values['end_date'] ) ? $this->writer->convert_to_utc( $this->form_values['end_date'] ) : null;
		if ( is_wp_error( $start_date ) || is_wp_error( $end_date ) ) {
			$error = is_wp_error( $start_date ) ? $start_date : $end_date;
			$this->add_admin_notice( 'error', $error->get_error_message() );
			return;
		}

		if ( $start_date && $end_date && $end_date <= $start_date ) {
			$this->add_admin_notice( 'error', __( 'End date must be after start date.', 'alynt-customer-groups' ) );
			return;
		}

		$result = $this->writer->save_pricing_rule(
			$this->form_values['group_id'],
			$this->form_values['discount_type'],
			$this->form_values['discount_value'],
			$this->form_values['product_ids'],
			$this->form_values['category_ids'],
			$start_date,
			$end_date
		);

		if ( $result ) {
			$this->form_values = array();
			$this->add_admin_notice( 'success', __( 'Pricing rule saved successfully.', 'alynt-customer-groups' ) );
			return;
		}

		$this->utils->log_error(
			'Pricing rule creation failed.',
			array(
				'group_id'       => $this->form_values['group_id'],
				'discount_type'  => $this->form_values['discount_type'],
				'discount_value' => $this->form_values['discount_value'],
				'product_count'  => count( $this->form_values['product_ids'] ),
				'category_count' => count( $this->form_values['category_ids'] ),
			)
		);
		$this->add_admin_notice( 'error', __( 'Could not save the pricing rule. Your selections are still here, so you can review them and try again.', 'alynt-customer-groups' ) );
	}

	/**
	 * Delete the submitted pricing rule.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function handle_delete_rule() {
		check_admin_referer( 'wccg_pricing_rules_action', 'wccg_pricing_rules_nonce' );

		$rule_id_raw = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;
		$rule_id     = $this->utils->sanitize_input( $rule_id_raw, 'int' );
		if ( ! $rule_id ) {
			$this->add_admin_notice( 'error', __( 'Invalid pricing rule selected. Refresh the page and try again.', 'alynt-customer-groups' ) );
			return;
		}

		$result = $this->writer->delete_pricing_rule( $rule_id );

		if ( $result !== false ) {
			$this->add_admin_notice( 'success', __( 'Pricing rule deleted successfully.', 'alynt-customer-groups' ) );
			return;
		}

		$this->utils->log_error(
			'Pricing rule deletion failed.',
			array(
				'rule_id' => $rule_id,
			)
		);
		$this->add_admin_notice( 'error', __( 'Could not delete the pricing rule. Please try again. If the problem continues, contact support.', 'alynt-customer-groups' ) );
	}

	/**
	 * Enable or disable all pricing rules in bulk.
	 *
	 * @since  1.0.0
	 * @param  int $status Target active status.
	 * @return void
	 */
	private function handle_bulk_status_change( $status ) {
		check_admin_referer( 'wccg_pricing_rules_action', 'wccg_pricing_rules_nonce' );

		$result = $this->writer->bulk_update_pricing_rule_status( $status );
		if ( false !== $result ) {
			$this->add_admin_notice( 'success', $status ? __( 'All pricing rules enabled successfully.', 'alynt-customer-groups' ) : __( 'All pricing rules disabled successfully.', 'alynt-customer-groups' ) );
			return;
		}

		$this->add_admin_notice( 'error', __( 'Could not update the pricing rules. Please try again.', 'alynt-customer-groups' ) );
	}

	/**
	 * Delete all pricing rules in bulk.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function handle_delete_all_rules() {
		check_admin_referer( 'wccg_pricing_rules_action', 'wccg_pricing_rules_nonce' );

		$result = $this->writer->delete_all_pricing_rules();
		if ( false !== $result ) {
			$this->add_admin_notice( 'success', __( 'All pricing rules deleted successfully.', 'alynt-customer-groups' ) );
			return;
		}

		$this->add_admin_notice( 'error', __( 'Could not delete the pricing rules. Please try again.', 'alynt-customer-groups' ) );
	}

	/**
	 * Extract and sanitize pricing rule form values from the current request.
	 *
	 * @since  1.0.0
	 * @return array<string,mixed> Sanitized rule form values.
	 */
	private function extract_form_values() {
		check_admin_referer( 'wccg_pricing_rules_action', 'wccg_pricing_rules_nonce' );

		$group_id_raw       = isset( $_POST['group_id'] ) ? absint( wp_unslash( $_POST['group_id'] ) ) : 0;
		$discount_type_raw  = isset( $_POST['discount_type'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_type'] ) ) : 'fixed';
		$discount_value_raw = isset( $_POST['discount_value'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_value'] ) ) : '';
		$product_ids_raw    = isset( $_POST['product_ids'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['product_ids'] ) ) : array();
		$category_ids_raw   = isset( $_POST['category_ids'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['category_ids'] ) ) : array();

		return array(
			'group_id'       => $this->utils->sanitize_input( $group_id_raw, 'group_id' ),
			'discount_type'  => $this->utils->sanitize_input( $discount_type_raw, 'discount_type' ),
			'discount_value' => $this->utils->sanitize_input( $discount_value_raw, 'price' ),
			'start_date'     => isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '',
			'end_date'       => isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '',
			'product_ids'    => array_values( array_filter( $product_ids_raw ) ),
			'category_ids'   => array_values( array_filter( $category_ids_raw ) ),
		);
	}

	/**
	 * Queue an admin notice for the Pricing Rules screen.
	 *
	 * @since  1.0.0
	 * @param  string $type    Notice type accepted by add_settings_error().
	 * @param  string $message Notice text.
	 * @return void
	 */
	private function add_admin_notice( $type, $message ) {
		add_settings_error( 'wccg_pricing_rules', 'wccg_notice', $message, $type );
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
