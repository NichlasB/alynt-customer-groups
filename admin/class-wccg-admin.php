<?php
/**
 * Admin coordinator: menus, assets, and the product list pricing column.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers admin menu pages, enqueues admin assets, and adds the Group Pricing column
 * to the WooCommerce Products list table.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin {
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Admin|null
	 */
	private static $instance = null;

	/**
	 * Shared utility helper.
	 *
	 * @var WCCG_Utilities
	 */
	private $utils;

	/**
	 * Database facade.
	 *
	 * @var WCCG_Database
	 */
	private $db;

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Admin
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize admin dependencies and register hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function __construct() {
		$this->utils = WCCG_Utilities::instance();
		$this->db    = WCCG_Database::instance();
		$this->init_hooks();
	}

	/**
	 * Register all admin hooks for menus, assets, and product columns.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function init_hooks() {
		/**
		 * Fires before the administration menu loads so plugin menu pages can be registered.
		 *
		 * @since 1.0.0
		 */
		add_action( 'admin_menu', array( $this, 'add_menu_items' ) );

		/**
		 * Fires when admin scripts and styles should be enqueued.
		 *
		 * @since 1.0.0
		 *
		 * @param string $hook_suffix The current admin page hook suffix.
		 */
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		/**
		 * Filters the columns shown on the Products list table.
		 *
		 * @since 1.0.0
		 *
		 * @param array $columns Existing product list table columns.
		 */
		add_filter( 'manage_product_posts_columns', array( $this, 'add_pricing_rule_column' ) );

		/**
		 * Fires when a custom column value is rendered for a product row.
		 *
		 * @since 1.0.0
		 *
		 * @param string $column  The current column name.
		 * @param int    $post_id The product post ID.
		 */
		add_action( 'manage_product_posts_custom_column', array( $this, 'display_pricing_rule_column' ), 10, 2 );
	}

	/**
	 * Register the Customer Groups top-level menu and its sub-pages.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function add_menu_items() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		add_menu_page( __( 'Customer Groups', 'alynt-customer-groups' ), __( 'Customer Groups', 'alynt-customer-groups' ), 'manage_woocommerce', 'wccg_customer_groups', array( $this, 'display_customer_groups_page' ), 'dashicons-groups', 56 );
		add_submenu_page( 'wccg_customer_groups', __( 'User Assignments', 'alynt-customer-groups' ), __( 'User Assignments', 'alynt-customer-groups' ), 'manage_woocommerce', 'wccg_user_assignments', array( $this, 'display_user_assignments_page' ) );
		add_submenu_page( 'wccg_customer_groups', __( 'Pricing Rules', 'alynt-customer-groups' ), __( 'Pricing Rules', 'alynt-customer-groups' ), 'manage_woocommerce', 'wccg_pricing_rules', array( $this, 'display_pricing_rules_page' ) );
	}

	/**
	 * Enqueue admin CSS and JavaScript on plugin and product admin pages.
	 *
	 * @since  1.0.0
	 * @param  string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'wccg_' ) === false && $hook !== 'product' ) {
			return;
		}

		$style_url  = WCCG_URL . 'assets/dist/admin/index.css';
		$script_url = WCCG_URL . 'assets/dist/admin/index.js';
		if ( ! file_exists( WCCG_PATH . 'assets/dist/admin/index.css' ) ) {
			$style_url = WCCG_URL . 'assets/css/admin.css';
		}
		if ( ! file_exists( WCCG_PATH . 'assets/dist/admin/index.js' ) ) {
			$script_url = WCCG_URL . 'assets/js/admin.js';
		}

		wp_enqueue_style( 'wccg-admin-styles', $style_url, array(), WCCG_VERSION );
		wp_enqueue_script( 'wccg-admin-script', $script_url, array( 'jquery', 'jquery-ui-sortable' ), WCCG_VERSION, true );
		wp_localize_script(
			'wccg-admin-script',
			'wccg_admin',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wccg_admin_nonce' ),
				'strings'  => array(
					'rule_conflict'                     => __( 'Warning: This rule conflicts with existing rules', 'alynt-customer-groups' ),
					'fixed_discount'                    => __( 'Fixed discounts take precedence over percentage discounts', 'alynt-customer-groups' ),
					'category_override'                 => __( 'Product-specific rules override category rules', 'alynt-customer-groups' ),
					'export_users_required'             => __( 'Please select at least one user to export.', 'alynt-customer-groups' ),
					'date_range_invalid'                => __( 'From date cannot be later than To date.', 'alynt-customer-groups' ),
					'discount_percentage_range'         => __( 'Percentage discount must be between 0 and 100.', 'alynt-customer-groups' ),
					'discount_fixed_negative'           => __( 'Fixed discount cannot be negative.', 'alynt-customer-groups' ),
					'select_placeholder'                => __( 'Search...', 'alynt-customer-groups' ),
					'status_active'                     => __( 'Active', 'alynt-customer-groups' ),
					'status_inactive'                   => __( 'Inactive', 'alynt-customer-groups' ),
					'rule_inactive'                     => __( 'Rule is inactive', 'alynt-customer-groups' ),
					'inactive_schedule_title'           => __( 'Note: Rule is currently inactive. Enable the toggle for schedule to take effect.', 'alynt-customer-groups' ),
					'inactive_schedule_warning'         => __( 'This rule is currently inactive. The schedule will not take effect until you enable the rule using the toggle switch.', 'alynt-customer-groups' ),
					'warning_label'                     => __( 'Warning:', 'alynt-customer-groups' ),
					'error_prefix'                      => __( 'Error:', 'alynt-customer-groups' ),
					'failed_update_rule_status'         => __( 'Could not update the rule status. Please try again.', 'alynt-customer-groups' ),
					'delete_all_confirm_one'            => __( 'Are you sure you want to delete ALL pricing rules? This action cannot be undone!', 'alynt-customer-groups' ),
					'delete_all_confirm_two'            => __( 'This will permanently delete ALL pricing rules. Are you absolutely sure?', 'alynt-customer-groups' ),
					'deleting'                          => __( 'Deleting...', 'alynt-customer-groups' ),
					'delete_all_label'                  => __( 'Delete All Pricing Rules', 'alynt-customer-groups' ),
					'failed_delete_pricing'             => __( 'Could not delete the pricing rules. Please try again.', 'alynt-customer-groups' ),
					'enabling'                          => __( 'Enabling...', 'alynt-customer-groups' ),
					'failed_enable_pricing'             => __( 'Could not enable the pricing rules. Please try again.', 'alynt-customer-groups' ),
					'disable_all_confirm'               => __( 'Are you sure you want to disable all pricing rules?', 'alynt-customer-groups' ),
					'disabling'                         => __( 'Disabling...', 'alynt-customer-groups' ),
					'failed_disable_pricing'            => __( 'Could not disable the pricing rules. Please try again.', 'alynt-customer-groups' ),
					'delete_group_confirm'              => __( 'Are you sure you want to delete this group? This action cannot be undone.', 'alynt-customer-groups' ),
					'group_name_max'                    => __( 'Group name cannot exceed 255 characters.', 'alynt-customer-groups' ),
					'bulk_action_notice'                => __( 'Please select at least one user before running a bulk action.', 'alynt-customer-groups' ),
					'bulk_assign_confirm_single'        => __( 'Assign the selected user to this group?', 'alynt-customer-groups' ),
					'bulk_assign_confirm_multiple'      => __( 'Assign the selected users to this group?', 'alynt-customer-groups' ),
					'bulk_unassign_confirm_single'      => __( 'Unassign the selected user from its current group?', 'alynt-customer-groups' ),
					'bulk_unassign_confirm_multiple'    => __( 'Unassign the selected users from their current groups?', 'alynt-customer-groups' ),
					'bulk_assign_processing_single'     => __( 'Assigning 1 user...', 'alynt-customer-groups' ),
					'bulk_assign_processing_multiple'   => __( 'Assigning selected users...', 'alynt-customer-groups' ),
					'bulk_unassign_processing_single'   => __( 'Unassigning 1 user...', 'alynt-customer-groups' ),
					'bulk_unassign_processing_multiple' => __( 'Unassigning selected users...', 'alynt-customer-groups' ),
					'bulk_export_processing_single'     => __( 'Exporting 1 user...', 'alynt-customer-groups' ),
					'bulk_export_processing_multiple'   => __( 'Exporting selected users...', 'alynt-customer-groups' ),
					'session_expired'                   => __( 'Your session has expired. Reload the page and try again.', 'alynt-customer-groups' ),
					'network_error'                     => __( 'Connection lost. Check your internet connection and try again.', 'alynt-customer-groups' ),
					'request_timeout'                   => __( 'The request took too long. Please try again.', 'alynt-customer-groups' ),
					'generic_request_error'             => __( 'Something unexpected happened. Please try again.', 'alynt-customer-groups' ),
					'retry_action'                      => __( 'Retry', 'alynt-customer-groups' ),
				),
			)
		);
	}

	/**
	 * Insert a "Group Pricing" column after the "Price" column in the Products list table.
	 *
	 * @since  1.0.0
	 * @param  array $columns Existing column definitions.
	 * @return array Modified column definitions.
	 */
	public function add_pricing_rule_column( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $column ) {
			$new_columns[ $key ] = $column;
			if ( $key === 'price' ) {
				$new_columns['pricing_rules'] = __( 'Group Pricing', 'alynt-customer-groups' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render the Group Pricing column content for a product row.
	 *
	 * @since  1.0.0
	 * @param  string $column  The column name being rendered.
	 * @param  int    $post_id The product post ID.
	 * @return void
	 */
	public function display_pricing_rule_column( $column, $post_id ) {
		if ( $column !== 'pricing_rules' ) {
			return;
		}

		global $wpdb;
		$product_rules = $this->db->get_product_pricing_rules( $post_id );

		$category_ids   = $this->db->get_all_product_categories( $post_id );
		$category_rules = array();
		if ( ! empty( $category_ids ) ) {
			foreach ( $category_ids as $category_id ) {
				$category_rules = array_merge(
					$category_rules,
					$this->db->get_category_pricing_rules( $category_id )
				);
			}
		}

		echo '<div class="wccg-rules-info">';
		if ( ! empty( $product_rules ) ) {
			echo '<div class="product-specific-rules"><strong>' . esc_html__( 'Product Rules:', 'alynt-customer-groups' ) . '</strong>';
			foreach ( $product_rules as $rule ) {
				$this->display_rule_info( $rule );
			}
			echo '</div>';
		}
		if ( ! empty( $category_rules ) ) {
			$disabled = ! empty( $product_rules ) ? ' disabled' : '';
			echo '<div class="category-rules' . esc_attr( $disabled ) . '"><strong>' . esc_html__( 'Category Rules:', 'alynt-customer-groups' ) . '</strong>';
			foreach ( $category_rules as $rule ) {
				$this->display_rule_info( $rule, $rule->category_name );
			}
			echo '</div>';
		}
		if ( empty( $product_rules ) && empty( $category_rules ) ) {
			echo '<span class="no-rules">' . esc_html__( 'No pricing rules', 'alynt-customer-groups' ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Render one pricing rule summary in the product list column.
	 *
	 * @since  1.0.0
	 * @param  object $rule          Pricing rule row with related display fields.
	 * @param  string $category_name Optional category label for category rules.
	 * @return void
	 */
	private function display_rule_info( $rule, $category_name = '' ) {
		$discount_text = $rule->discount_type === 'percentage' ? $rule->discount_value . '%' : wc_price( $rule->discount_value );
		$tooltip       = __( 'Discount:', 'alynt-customer-groups' ) . ' ' . wp_strip_all_tags( $discount_text ) . "\n";
		$tooltip      .= __( 'Type:', 'alynt-customer-groups' ) . ' ' . ucfirst( $rule->discount_type ) . "\n";
		$tooltip      .= __( 'Created:', 'alynt-customer-groups' ) . ' ' . date_i18n( get_option( 'date_format' ), strtotime( $rule->created_at ) );
		if ( $category_name ) {
			$tooltip .= "\n" . __( 'Category:', 'alynt-customer-groups' ) . ' ' . $category_name;
		}

		echo '<div class="rule-info" title="' . esc_attr( $tooltip ) . '"><span class="group-name">' . esc_html( $rule->group_name ) . '</span>: <span class="discount">' . esc_html( $discount_text ) . '</span>';
		if ( $rule->discount_type === 'fixed' ) {
			echo ' <span class="priority-indicator" title="' . esc_attr__( 'Fixed discounts take precedence over percentage discounts', 'alynt-customer-groups' ) . '">&#9733;</span>';
		}
		echo '</div>';
	}

	/**
	 * Render the Customer Groups admin page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function display_customer_groups_page() {
		WCCG_Admin_Customer_Groups::instance()->display_page();
	}

	/**
	 * Render the User Assignments admin page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function display_user_assignments_page() {
		WCCG_Admin_User_Assignments::instance()->display_page();
	}

	/**
	 * Render the Pricing Rules admin page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function display_pricing_rules_page() {
		WCCG_Admin_Pricing_Rules::instance()->display_page();
	}
}
