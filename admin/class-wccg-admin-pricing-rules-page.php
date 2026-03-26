<?php
/**
 * Pricing Rules admin page rendering and script enqueueing.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Pricing Rules admin page and enqueues page-specific scripts.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_Pricing_Rules_Page {
	/**
	 * Default row count shown per page in the pricing rules table.
	 *
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 25;

	/**
	 * Allowed per-page options in the pricing rules table.
	 *
	 * @var int[]
	 */
	private const PER_PAGE_OPTIONS = array( 25, 50, 100 );

	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Admin_Pricing_Rules_Page|null
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
	 * Pricing rule write service.
	 *
	 * @var WCCG_Pricing_Rule_Write_Service
	 */
	private $writer;

	/**
	 * Pricing rules page form actions handler.
	 *
	 * @var WCCG_Admin_Pricing_Rules_Actions
	 */
	private $actions;

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Admin_Pricing_Rules_Page
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the Pricing Rules page dependencies and hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function __construct() {
		$this->utils   = WCCG_Utilities::instance();
		$this->db      = WCCG_Database::instance();
		$this->writer  = WCCG_Pricing_Rule_Write_Service::instance();
		$this->actions = WCCG_Admin_Pricing_Rules_Actions::instance();

		/**
		 * Fires when admin scripts and styles should be enqueued.
		 *
		 * @since 1.0.0
		 *
		 * @param string $hook_suffix The current admin page hook suffix.
		 */
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue Pricing Rules page scripts and localize the AJAX nonce.
	 *
	 * @since  1.0.0
	 * @param  string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'customer-groups_page_wccg_pricing_rules' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script( 'wccg-admin-script' );
		if ( wp_script_is( 'selectWoo', 'registered' ) ) {
			wp_enqueue_script( 'selectWoo' );
		} elseif ( wp_script_is( 'select2', 'registered' ) ) {
			wp_enqueue_script( 'select2' );
		}
		if ( wp_style_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
		}
		if ( ! file_exists( WCCG_PATH . 'assets/dist/admin/index.js' ) ) {
			wp_enqueue_script(
				'wccg-pricing-rules-remote',
				WCCG_URL . 'assets/js/pricing-rules-remote.js',
				array( 'wccg-admin-script', 'jquery' ),
				WCCG_VERSION,
				true
			);
		}
		wp_localize_script(
			'wccg-admin-script',
			'wccg_pricing_rules',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wccg_pricing_rules_ajax' ),
				'strings'  => array(
					'search_products_placeholder'   => __( 'Search and select products...', 'alynt-customer-groups' ),
					'search_categories_placeholder' => __( 'Search and select categories...', 'alynt-customer-groups' ),
					'end_date_after_start'          => __( 'End date must be after start date.', 'alynt-customer-groups' ),
					'failed_update_rule_order'      => __( 'Could not save the new rule order. Please try again.', 'alynt-customer-groups' ),
					'error_update_rule_order'       => __( 'Could not save the new rule order. Please try again.', 'alynt-customer-groups' ),
					'saving'                        => __( 'Saving...', 'alynt-customer-groups' ),
					'updating_schedule'             => __( 'Updating schedule...', 'alynt-customer-groups' ),
					'updating_rule'                 => __( 'Updating rule...', 'alynt-customer-groups' ),
					'loading_rule_data'             => __( 'Loading rule data...', 'alynt-customer-groups' ),
					'failed_load_rule_data'         => __( 'Could not load the pricing rule. Please try again.', 'alynt-customer-groups' ),
					'save_schedule'                 => __( 'Save Schedule', 'alynt-customer-groups' ),
					'save_changes'                  => __( 'Save Changes', 'alynt-customer-groups' ),
					'percentage_hint'               => __( 'Enter a percentage between 0 and 100.', 'alynt-customer-groups' ),
					'fixed_hint'                    => __( 'Enter the fixed discount amount.', 'alynt-customer-groups' ),
					'select_customer_group'         => __( 'Please select a customer group.', 'alynt-customer-groups' ),
					'enter_valid_discount'          => __( 'Please enter a valid discount value.', 'alynt-customer-groups' ),
					'percentage_exceed'             => __( 'Percentage discount cannot exceed 100%.', 'alynt-customer-groups' ),
					'fixed_precedence_short'        => __( 'Fixed discounts take precedence', 'alynt-customer-groups' ),
					'product_rule'                  => __( 'Product Rule', 'alynt-customer-groups' ),
					'category_rule'                 => __( 'Category Rule', 'alynt-customer-groups' ),
					'rule_update_error'             => __( 'Could not update the pricing rule. Please try again.', 'alynt-customer-groups' ),
					'schedule_update_error'         => __( 'Could not update the schedule. Please try again.', 'alynt-customer-groups' ),
					'error_prefix'                  => __( 'Error:', 'alynt-customer-groups' ),
					'session_expired'               => __( 'Your session has expired. Reload the page and try again.', 'alynt-customer-groups' ),
					'network_error'                 => __( 'Connection lost. Check your internet connection and try again.', 'alynt-customer-groups' ),
					'request_timeout'               => __( 'The request took too long. Please try again.', 'alynt-customer-groups' ),
					'generic_request_error'         => __( 'Something unexpected happened. Please try again.', 'alynt-customer-groups' ),
					'saving_rule_order'             => __( 'Saving rule order...', 'alynt-customer-groups' ),
					'rule_order_saved'              => __( 'Rule order saved.', 'alynt-customer-groups' ),
					'rule_order_timeout'            => __( 'Saving the rule order took too long. Please try again.', 'alynt-customer-groups' ),
					'dragging_rule_order'           => __( 'Rule order changed. Release to save.', 'alynt-customer-groups' ),
					'order_save_queued'             => __( 'Saving the latest order after the current update finishes...', 'alynt-customer-groups' ),
					'rule_load_timeout'             => __( 'Loading the pricing rule took too long. Please try again.', 'alynt-customer-groups' ),
					'rule_update_timeout'           => __( 'Saving the pricing rule took too long. Please try again.', 'alynt-customer-groups' ),
					'retry_now_confirm'             => __( 'Retry now?', 'alynt-customer-groups' ),
				),
			)
		);
		$select_script_registered = wp_script_is( 'selectWoo', 'registered' ) || wp_script_is( 'select2', 'registered' );
		if ( ! $select_script_registered ) {
			wp_add_inline_style( 'woocommerce_admin_styles', '.woocommerce select:not(.select2-hidden-accessible) { display: block !important; visibility: visible !important; } .select2-container { display: none !important; }' );
		}
	}

	/**
	 * Handle any pending form submissions, fetch all required data, and render the page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function display_page() {
		$this->utils->verify_admin_access();
		$this->actions->handle_form_submission();

		$groups             = $this->db->get_groups();
		$form_values        = $this->actions->get_form_values();
		$all_products       = WCCG_Admin_Pricing_Rules_View_Helper::get_product_options_by_ids( $form_values['product_ids'] );
		$all_categories     = WCCG_Admin_Pricing_Rules_View_Helper::get_category_options_by_ids( $form_values['category_ids'] );
		$group_names        = WCCG_Admin_Pricing_Rules_View_Helper::get_group_names_by_ids( wp_list_pluck( $groups, 'group_id' ) );
		$per_page           = $this->get_per_page();
		$current_page       = $this->get_current_page();
		$total_rules        = $this->db->count_pricing_rules_for_admin_page();
		$total_pages        = max( 1, (int) ceil( $total_rules / $per_page ) );
		$current_page       = min( $current_page, $total_pages );
		$pricing_rules      = $this->db->get_pricing_rules_for_admin_page(
			array(
				'limit'  => $per_page,
				'offset' => ( $current_page - 1 ) * $per_page,
			)
		);
		$pricing_rules_view = WCCG_Admin_Pricing_Rules_View_Helper::build_pricing_rules_view( $pricing_rules, $group_names );
		$rule_order_enabled = $total_pages <= 1;
		$pagination         = $this->get_pagination_args( $current_page, $per_page, $total_rules );
		$conflicts          = array();
		$conflicts_notice   = '';

		if ( $total_rules <= 200 ) {
			$conflicts = $this->writer->get_rule_conflicts( 10 );
		} elseif ( $total_rules > 0 ) {
			$conflicts_notice = __( 'Conflict scanning is skipped when the rule list is large to keep this page responsive.', 'alynt-customer-groups' );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pricing Rules', 'alynt-customer-groups' ); ?></h1>
			<?php include WCCG_PATH . 'admin/views/html-pricing-rules-info-box.php'; ?>
			<?php settings_errors( 'wccg_pricing_rules' ); ?>
			<form method="post">
				<?php wp_nonce_field( 'wccg_pricing_rules_action', 'wccg_pricing_rules_nonce' ); ?>
				<input type="hidden" name="action" value="save_rule">
				<?php include WCCG_PATH . 'admin/views/html-pricing-rules-form.php'; ?>
			</form>
			<h2><?php esc_html_e( 'Existing Pricing Rules', 'alynt-customer-groups' ); ?></h2>
			<?php include WCCG_PATH . 'admin/views/html-pricing-rules-list.php'; ?>
		</div>
		<?php
	}

	/**
	 * Return the current page number for the pricing rules table.
	 *
	 * @since  1.2.0
	 * @return int Current page number.
	 */
	private function get_current_page() {
		$current_page = isset( $_GET['paged'] ) ? absint( sanitize_text_field( wp_unslash( $_GET['paged'] ) ) ) : 1;
		return max( 1, $current_page );
	}

	/**
	 * Return the selected per-page value for the pricing rules table.
	 *
	 * @since  1.2.0
	 * @return int Per-page value.
	 */
	private function get_per_page() {
		$per_page = isset( $_GET['per_page'] ) ? absint( sanitize_text_field( wp_unslash( $_GET['per_page'] ) ) ) : self::DEFAULT_PER_PAGE;

		return in_array( $per_page, self::PER_PAGE_OPTIONS, true ) ? $per_page : self::DEFAULT_PER_PAGE;
	}

	/**
	 * Build pagination metadata for the pricing rules table.
	 *
	 * @since  1.2.0
	 * @param  int $current_page Current page number.
	 * @param  int $per_page     Per-page value.
	 * @param  int $total_rules  Total pricing rule count.
	 * @return array
	 */
	private function get_pagination_args( $current_page, $per_page, $total_rules ) {
		$total_pages = max( 1, (int) ceil( $total_rules / $per_page ) );
		$from_item   = 0;
		$to_item     = 0;

		if ( $total_rules > 0 ) {
			$from_item = ( ( $current_page - 1 ) * $per_page ) + 1;
			$to_item   = min( $current_page * $per_page, $total_rules );
		}

		return array(
			'current_page'     => $current_page,
			'per_page'         => $per_page,
			'per_page_options' => self::PER_PAGE_OPTIONS,
			'total_items'      => $total_rules,
			'total_pages'      => $total_pages,
			'from_item'        => $from_item,
			'to_item'          => $to_item,
		);
	}
}
