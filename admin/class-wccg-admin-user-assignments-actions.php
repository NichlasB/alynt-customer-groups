<?php
/**
 * Form action handlers for user-group assignments, unassignments, and CSV export.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes POST submissions on the User Assignments admin page.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_User_Assignments_Actions {
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Admin_User_Assignments_Actions|null
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
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Admin_User_Assignments_Actions
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the action handler dependencies.
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
	 * Verifies the wccg_user_assignments_action nonce before dispatching.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function handle_form_submission() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}

		if ( ! isset( $_POST['wccg_user_assignments_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wccg_user_assignments_nonce'] ) ), 'wccg_user_assignments_action' ) ) {
			$this->add_admin_notice( 'error', __( 'Your security token expired or is invalid. Reload the page and try again.', 'alynt-customer-groups' ) );
			return;
		}

		if ( isset( $_POST['export_csv'] ) ) {
			$this->handle_csv_export();
			return;
		}

		if ( ! isset( $_POST['action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['action'] ) );

		switch ( $action ) {
			case 'assign_users':
				$this->handle_user_assignments();
				break;
			case 'unassign_users':
				$this->handle_user_unassignments();
				break;
		}
	}

	/**
	 * Export the selected users and their group assignments as a CSV file.
	 *
	 * @since  1.0.0
	 * @return void
	 * @throws Exception When the CSV stream cannot be opened or written.
	 */
	private function handle_csv_export() {
		check_admin_referer( 'wccg_user_assignments_action', 'wccg_user_assignments_nonce' );

		if ( ! isset( $_POST['user_ids'] ) || empty( $_POST['user_ids'] ) ) {
			$this->add_admin_notice( 'error', __( 'Please select users to export.', 'alynt-customer-groups' ) );
			return;
		}

		$user_ids         = array_values( array_unique( array_map( 'intval', wp_unslash( (array) $_POST['user_ids'] ) ) ) );
		$max_export_users = 1000;
		if ( count( $user_ids ) > $max_export_users ) {
			$this->add_admin_notice(
				'error',
				sprintf(
					/* translators: %d: maximum number of users allowed in a CSV export batch. */
					__( 'Maximum of %d users can be exported at once.', 'alynt-customer-groups' ),
					$max_export_users
				)
			);
			return;
		}

		$export_rows      = array();
		$missing_user_ids = array();

		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				$missing_user_ids[] = $user_id;
				continue;
			}

			$group_name = $this->db->get_user_group_name( $user_id );

			$export_rows[] = array(
				$user->ID,
				$user->user_login,
				$user->user_email,
				$user->first_name,
				$user->last_name,
				$group_name ? $group_name : __( 'Unassigned', 'alynt-customer-groups' ),
				$user->user_registered,
			);
		}

		if ( ! empty( $missing_user_ids ) ) {
			$this->utils->log_error(
				'CSV export blocked because selected users were missing.',
				array(
					'requested_user_ids' => $user_ids,
					'missing_user_ids'   => $missing_user_ids,
				)
			);
			$this->add_admin_notice(
				'error',
				sprintf(
					/* translators: %d: number of selected users that could not be exported. */
					__( 'Could not export %d selected users because they no longer exist. Refresh the page and try again.', 'alynt-customer-groups' ),
					count( $missing_user_ids )
				)
			);
			return;
		}

		if ( ob_get_level() ) {
			ob_end_clean();
		}

		remove_all_actions( 'shutdown' );
		$output = null;

		try {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=customer-groups-export-' . gmdate( 'Y-m-d' ) . '.csv' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );

			$output = fopen( 'php://output', 'w' );
			if ( $output === false ) {
				throw new Exception( 'Failed to open output stream' );
			}

			fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
			$header_written = fputcsv(
				$output,
				array(
					__( 'User ID', 'alynt-customer-groups' ),
					__( 'Username', 'alynt-customer-groups' ),
					__( 'Email', 'alynt-customer-groups' ),
					__( 'First Name', 'alynt-customer-groups' ),
					__( 'Last Name', 'alynt-customer-groups' ),
					__( 'Customer Group', 'alynt-customer-groups' ),
					__( 'Registration Date', 'alynt-customer-groups' ),
				)
			);

			if ( false === $header_written ) {
				throw new Exception( 'Failed to write CSV header row' );
			}

			foreach ( $export_rows as $row ) {
				$row_written = fputcsv( $output, $row );
				if ( false === $row_written ) {
					throw new Exception( 'Failed to write CSV data row' );
				}
			}

			exit();
		} catch ( Exception $e ) {
			$this->utils->log_error(
				'CSV export failed.',
				array(
					'user_ids' => $user_ids,
					'error'    => $e->getMessage(),
				)
			);
			wp_die( esc_html__( 'Could not generate the CSV file. Please try again.', 'alynt-customer-groups' ) );
		}
	}

	/**
	 * Assign the selected users to the submitted customer group.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function handle_user_assignments() {
		check_admin_referer( 'wccg_user_assignments_action', 'wccg_user_assignments_nonce' );

		if ( ! isset( $_POST['user_ids'] ) || empty( $_POST['user_ids'] ) || ! isset( $_POST['group_id'] ) ) {
			$this->add_admin_notice( 'error', __( 'Please select users and a group.', 'alynt-customer-groups' ) );
			return;
		}

		if ( ! $this->utils->check_rate_limit( get_current_user_id(), 'group_change' ) ) {
			$this->add_admin_notice( 'error', __( 'Too many group assignments attempted. Please wait a few minutes and try again.', 'alynt-customer-groups' ) );
			return;
		}

		$user_ids     = array_values( array_unique( array_map( 'intval', wp_unslash( (array) $_POST['user_ids'] ) ) ) );
		$group_id_raw = absint( wp_unslash( $_POST['group_id'] ) );
		$group_id     = $this->utils->sanitize_input( $group_id_raw, 'group_id' );

		if ( empty( $user_ids ) || empty( $group_id ) ) {
			$this->add_admin_notice( 'error', __( 'Invalid user IDs or group ID provided.', 'alynt-customer-groups' ) );
			return;
		}

		$max_users_per_batch = 100;
		if ( count( $user_ids ) > $max_users_per_batch ) {
			$this->add_admin_notice(
				'error',
				sprintf(
					/* translators: %d: maximum number of users allowed in a group assignment batch. */
					__( 'Maximum of %d users can be assigned at once.', 'alynt-customer-groups' ),
					$max_users_per_batch
				)
			);
			return;
		}

		$result = $this->db->bulk_assign_user_groups( $user_ids, $group_id );
		if ( $result ) {
			$this->add_admin_notice(
				'success',
				sprintf(
					/* translators: %d: number of users assigned. */
					_n( 'Assigned %d user to the selected group.', 'Assigned %d users to the selected group.', count( $user_ids ), 'alynt-customer-groups' ),
					count( $user_ids )
				)
			);
			return;
		}

		$this->utils->log_error(
			'Bulk user assignment failed.',
			array(
				'user_ids' => $user_ids,
				'group_id' => $group_id,
			)
		);
		$this->add_admin_notice( 'error', __( 'Could not assign the selected users. Please try again. If the problem continues, contact support.', 'alynt-customer-groups' ) );
	}

	/**
	 * Remove group assignments for the selected users.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function handle_user_unassignments() {
		check_admin_referer( 'wccg_user_assignments_action', 'wccg_user_assignments_nonce' );

		if ( ! isset( $_POST['user_ids'] ) || empty( $_POST['user_ids'] ) ) {
			$this->add_admin_notice( 'error', __( 'Please select users to unassign.', 'alynt-customer-groups' ) );
			return;
		}

		if ( ! $this->utils->check_rate_limit( get_current_user_id(), 'group_change' ) ) {
			$this->add_admin_notice( 'error', __( 'Too many group changes attempted. Please wait a few minutes and try again.', 'alynt-customer-groups' ) );
			return;
		}

		$user_ids = array_values( array_unique( array_map( 'intval', wp_unslash( (array) $_POST['user_ids'] ) ) ) );
		if ( empty( $user_ids ) ) {
			$this->add_admin_notice( 'error', __( 'Invalid user IDs provided.', 'alynt-customer-groups' ) );
			return;
		}

		$max_users_per_batch = 100;
		if ( count( $user_ids ) > $max_users_per_batch ) {
			$this->add_admin_notice(
				'error',
				sprintf(
					/* translators: %d: maximum number of users allowed in an unassignment batch. */
					__( 'Maximum of %d users can be unassigned at once.', 'alynt-customer-groups' ),
					$max_users_per_batch
				)
			);
			return;
		}

		$result = $this->db->bulk_unassign_user_groups( $user_ids );

		if ( $result !== false ) {
			$this->add_admin_notice(
				'success',
				sprintf(
					/* translators: 1: number of selected users processed, 2: number of users unassigned. */
					__( 'Processed %1$d selected users. Removed group assignments from %2$d users.', 'alynt-customer-groups' ),
					count( $user_ids ),
					(int) $result
				)
			);
			return;
		}

		$this->utils->log_error(
			'Bulk user unassignment failed.',
			array(
				'user_ids' => $user_ids,
			)
		);
		$this->add_admin_notice( 'error', __( 'Could not unassign the selected users. Please try again. If the problem continues, contact support.', 'alynt-customer-groups' ) );
	}

	/**
	 * Queue an admin notice for the User Assignments screen.
	 *
	 * @since  1.0.0
	 * @param  string $type    Notice type accepted by add_settings_error().
	 * @param  string $message Notice text.
	 * @return void
	 */
	private function add_admin_notice( $type, $message ) {
		add_settings_error(
			'wccg_user_assignments',
			'wccg_notice',
			$message,
			$type
		);
	}
}
