<?php
/**
 * Data queries for the User Assignments admin page.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches paginated, filtered user lists and supporting data for the User Assignments screen.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_User_Assignments_Query {
	/**
	 * Fallback label for users without an assigned customer group.
	 *
	 * @var string
	 */
	private const UNASSIGNED_GROUP_LABEL = 'Unassigned';

	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Admin_User_Assignments_Query|null
	 */
	private static $instance = null;

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
	 * @return WCCG_Admin_User_Assignments_Query
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the query helper dependencies.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function __construct() {
		$this->db = WCCG_Database::instance();
	}

	/**
	 * Fetch a paginated list of users matching the given filter parameters.
	 *
	 * First and last name meta values are appended to each user object.
	 *
	 * @since  1.0.0
	 * @param  array $params User filter parameters.
	 * @return WP_User[] Array of user objects with first_name and last_name appended.
	 */
	public function get_users( $params ) {
		$args  = $this->build_user_query_args( $params );
		$users = get_users( $args );

		foreach ( $users as $user ) {
			$user->first_name = get_user_meta( $user->ID, 'first_name', true );
			$user->last_name  = get_user_meta( $user->ID, 'last_name', true );
		}

		return $users;
	}

	/**
	 * Count total users matching the given filter parameters (ignores pagination).
	 *
	 * @since  1.0.0
	 * @param  array $params Same filter params as get_users(), pagination fields are ignored.
	 * @return int Total matching user count.
	 */
	public function get_total_users( $params ) {
		$query = new WP_User_Query( $this->build_user_query_args( $params, true ) );

		return (int) $query->get_total();
	}

	/**
	 * Fetch all customer groups ordered by name.
	 *
	 * @since  1.0.0
	 * @return object[] Array of stdClass rows from the customer_groups table.
	 */
	public function get_groups() {
		global $wpdb;
		return $wpdb->get_results( "SELECT group_id, group_name FROM {$wpdb->prefix}customer_groups ORDER BY group_name ASC" );
	}

	/**
	 * Fetch all user-group assignments keyed by user_id.
	 *
	 * @since  1.0.0
	 * @param  int[] $user_ids WordPress user IDs.
	 * @return object[] Associative array of stdClass rows from the user_groups table, keyed by user_id.
	 */
	public function get_user_groups( $user_ids = array() ) {
		global $wpdb;

		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		if ( empty( $user_ids ) ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, group_id FROM {$wpdb->prefix}user_groups WHERE user_id IN (" . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ')',
				...$user_ids
			),
			OBJECT_K
		);
	}

	/**
	 * Resolve the display name of a user's group, cleaning up orphaned assignments if needed.
	 *
	 * @since  1.0.0
	 * @param  int      $user_id     WordPress user ID.
	 * @param  object[] $user_groups Keyed user-group rows from get_user_groups().
	 * @param  object[] $groups      Group rows from get_groups().
	 * @return string Group name, or 'Unassigned' if the user has no group.
	 */
	public function get_user_group_name( $user_id, $user_groups, $groups ) {
		if ( ! isset( $user_groups[ $user_id ] ) ) {
			return self::UNASSIGNED_GROUP_LABEL;
		}

		$group_id = $user_groups[ $user_id ]->group_id;
		foreach ( $groups as $group ) {
			if ( $group->group_id === $group_id ) {
				return esc_html( $group->group_name );
			}
		}

		// Assignment references a deleted group — clean it up opportunistically.
		$this->db->cleanup_orphaned_group_assignments();
		return self::UNASSIGNED_GROUP_LABEL;
	}

	/**
	 * Build WP_User_Query arguments from the supplied filter parameters.
	 *
	 * @since  1.0.0
	 * @param  array $params    User filter parameters.
	 * @param  bool  $for_total Whether to build a total-count query.
	 * @return array Query arguments for WP_User_Query.
	 */
	private function build_user_query_args( $params, $for_total = false ) {
		$args = array(
			'fields'      => $for_total ? 'ID' : array( 'ID', 'user_login', 'user_email', 'user_registered' ),
			'number'      => $for_total ? 1 : $params['users_per_page'],
			'paged'       => $for_total ? 1 : $params['current_page'],
			'orderby'     => $params['orderby'],
			'order'       => $params['order'],
			'count_total' => $for_total,
		);

		if ( ! $for_total ) {
			$args['count_total'] = false;
		}

		if ( ! empty( $params['date_from'] ) || ! empty( $params['date_to'] ) ) {
			$date_query = array();
			if ( ! empty( $params['date_from'] ) ) {
				$date_query['after'] = $params['date_from'];
			}
			if ( ! empty( $params['date_to'] ) ) {
				$date_query['before'] = $params['date_to'];
			}
			$date_query['inclusive'] = true;
			$args['date_query']      = array( $date_query );
		}

		if ( ! empty( $params['search'] ) ) {
			$args['search']         = '*' . $params['search'] . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		if ( $params['group_filter'] > 0 ) {
			$users_in_group  = $this->db->get_users_in_group( $params['group_filter'] );
			$args['include'] = ! empty( $users_in_group ) ? $users_in_group : array( 0 );
		}

		return $args;
	}
}
