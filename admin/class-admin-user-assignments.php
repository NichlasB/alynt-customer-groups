<?php
/**
 * User Assignments admin page: display and sorting.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the User Assignments admin page, handling query parameter parsing
 * and column sorting URL generation.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_User_Assignments {
	/**
	 * Default users-per-page value.
	 *
	 * @var int
	 */
	private const DEFAULT_USERS_PER_PAGE = 100;

	/**
	 * Allowed users-per-page options.
	 *
	 * @var int[]
	 */
	private const ALLOWED_USERS_PER_PAGE = array( 100, 200, 500, 1000 );

	/**
	 * Allowed sorting columns.
	 *
	 * @var string[]
	 */
	private const ALLOWED_ORDERBY = array( 'ID', 'display_name', 'user_login', 'first_name', 'last_name', 'user_email', 'user_registered' );

	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Admin_User_Assignments|null
	 */
	private static $instance = null;

	/**
	 * Shared utility helper.
	 *
	 * @var WCCG_Utilities
	 */
	private $utils;

	/**
	 * Query helper for user assignment data.
	 *
	 * @var WCCG_Admin_User_Assignments_Query
	 */
	private $query;

	/**
	 * Form action handler.
	 *
	 * @var WCCG_Admin_User_Assignments_Actions
	 */
	private $actions;

	/**
	 * Sanitized request parameters for the current page load.
	 *
	 * @var array<string,mixed>
	 */
	private $params;

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Admin_User_Assignments
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize user assignments page dependencies.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function __construct() {
		$this->utils   = WCCG_Utilities::instance();
		$this->query   = WCCG_Admin_User_Assignments_Query::instance();
		$this->actions = WCCG_Admin_User_Assignments_Actions::instance();
	}

	/**
	 * Handle any pending form submissions, then render the user assignments page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function display_page() {
		$this->utils->verify_admin_access();
		$this->setup_page_params();
		$this->actions->handle_form_submission();

		$users            = $this->query->get_users( $this->params );
		$total_users      = $this->query->get_total_users( $this->params );
		$groups           = $this->query->get_groups();
		$user_groups      = $this->query->get_user_groups( wp_list_pluck( $users, 'ID' ) );
		$sorting_urls     = array(
			'display_name'    => $this->get_sorting_url( 'display_name' ),
			'user_email'      => $this->get_sorting_url( 'user_email' ),
			'user_registered' => $this->get_sorting_url( 'user_registered' ),
		);
		$sort_indicators  = array(
			'display_name'    => $this->get_sort_indicator( 'display_name' ),
			'user_email'      => $this->get_sort_indicator( 'user_email' ),
			'user_registered' => $this->get_sort_indicator( 'user_registered' ),
		);
		$per_page_options = self::ALLOWED_USERS_PER_PAGE;
		$params           = $this->params;
		$query            = $this->query;

		include WCCG_PATH . 'admin/views/html-user-assignments-page.php';
	}

	/**
	 * Build sanitized query parameters for the current page request.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function setup_page_params() {
		$this->params = array(
			'search'         => $this->sanitize_query_param( 'search', '' ),
			'users_per_page' => $this->sanitize_query_param( 'per_page', self::DEFAULT_USERS_PER_PAGE, 'int' ),
			'current_page'   => $this->sanitize_query_param( 'paged', 1, 'int' ),
			'orderby'        => $this->sanitize_query_param( 'orderby', 'ID' ),
			'order'          => $this->sanitize_query_param( 'order', 'ASC' ),
			'group_filter'   => $this->sanitize_query_param( 'group_filter', 0, 'group_id' ),
			'date_from'      => $this->sanitize_query_param( 'date_from', '' ),
			'date_to'        => $this->sanitize_query_param( 'date_to', '' ),
		);

		if ( ! in_array( $this->params['users_per_page'], self::ALLOWED_USERS_PER_PAGE, true ) ) {
			$this->params['users_per_page'] = self::DEFAULT_USERS_PER_PAGE;
		}

		if ( ! in_array( $this->params['orderby'], self::ALLOWED_ORDERBY, true ) ) {
			$this->params['orderby'] = 'ID';
		}

		$this->params['order'] = strtoupper( $this->params['order'] );
		if ( ! in_array( $this->params['order'], array( 'ASC', 'DESC' ), true ) ) {
			$this->params['order'] = 'ASC';
		}
	}

	/**
	 * Build a sorting URL for the requested column.
	 *
	 * @since  1.0.0
	 * @param  string $column Column key.
	 * @return string Sorting URL.
	 */
	private function get_sorting_url( $column ) {
		$new_order = ( $this->params['orderby'] === $column && $this->params['order'] === 'ASC' ) ? 'DESC' : 'ASC';

		return add_query_arg(
			array(
				'orderby'      => $column,
				'order'        => $new_order,
				'search'       => $this->params['search'],
				'per_page'     => $this->params['users_per_page'],
				'paged'        => 1,
				'group_filter' => $this->params['group_filter'],
				'date_from'    => $this->params['date_from'],
				'date_to'      => $this->params['date_to'],
			)
		);
	}

	/**
	 * Return the visual sort indicator for a column.
	 *
	 * @since  1.0.0
	 * @param  string $column Column key.
	 * @return string Sort indicator suffix.
	 */
	private function get_sort_indicator( $column ) {
		if ( $this->params['orderby'] === $column ) {
			return ( $this->params['order'] === 'ASC' ) ? ' ↑' : ' ↓';
		}

		return '';
	}

	/**
	 * Sanitize a query parameter value from the current request.
	 *
	 * @since  1.0.0
	 * @param  string $key           Query key.
	 * @param  mixed  $default_value Default value when key is missing.
	 * @param  string $type          Sanitization type handled by WCCG_Utilities::sanitize_input().
	 * @return mixed
	 */
	private function sanitize_query_param( $key, $default_value, $type = 'text' ) {
		$raw_query = filter_input( INPUT_GET, $key, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		if ( is_array( $raw_query ) ) {
			return $default_value;
		}

		$raw_query = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW );
		if ( null === $raw_query || false === $raw_query ) {
			return $default_value;
		}

		$raw_value = wp_unslash( $raw_query );

		switch ( $type ) {
			case 'int':
				return absint( $raw_value );
			case 'group_id':
				return $this->utils->sanitize_input( absint( $raw_value ), 'group_id' );
			default:
				return sanitize_text_field( $raw_value );
		}
	}
}
