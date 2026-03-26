<?php
/**
 * Customer Groups admin page: list, add, delete, and default-group settings.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Customer Groups admin page and coordinates with the actions handler.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_Customer_Groups {
	private static $instance = null;
	private $db;
	private $utils;
	private $actions;

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Admin_Customer_Groups
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->db      = WCCG_Database::instance();
		$this->utils   = WCCG_Utilities::instance();
		$this->actions = WCCG_Admin_Customer_Groups_Actions::instance();
	}

	/**
	 * Handle any pending form submissions, then render the customer groups page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function display_page() {
		$this->utils->verify_admin_access();
		$this->actions->handle_form_submission();
		$groups = $this->get_groups();
		$this->render_page( $groups );
	}

	private function get_groups() {
		global $wpdb;

		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}customer_groups ORDER BY group_name ASC"
		);
	}

	private function render_page( $groups ) {
		$persisted_notice = get_option( 'wccg_customer_groups_action_notice', array() );
		if ( is_array( $persisted_notice ) && ! empty( $persisted_notice['type'] ) && ! empty( $persisted_notice['message'] ) ) {
			add_settings_error( 'wccg_customer_groups', 'wccg_notice_persisted', $persisted_notice['message'], $persisted_notice['type'] );
			delete_option( 'wccg_customer_groups_action_notice' );
		}

		$default_group_id   = (int) get_option( 'wccg_default_group_id', 0 );
		$custom_title       = get_option( 'wccg_default_group_custom_title', '' );
		$default_group_name = '';

		foreach ( $groups as $group ) {
			if ( (int) $group->group_id === $default_group_id ) {
				$default_group_name = $group->group_name;
				break;
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Customer Groups', 'alynt-customer-groups' ); ?></h1>
			<?php settings_errors( 'wccg_customer_groups' ); ?>
			<?php include WCCG_PATH . 'admin/views/html-default-group-settings.php'; ?>
			<?php include WCCG_PATH . 'admin/views/html-customer-groups-list.php'; ?>
		</div>
		<?php
	}
}

