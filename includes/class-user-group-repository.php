<?php
/**
 * Database read/write operations for user-group assignments.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queries and updates the user_groups table that maps WordPress users to customer groups.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_User_Group_Repository {
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_User_Group_Repository|null
	 */
	private static $instance = null;

	/**
	 * Database facade.
	 *
	 * @var WCCG_Database
	 */
	private $db;

	/**
	 * Cached group IDs keyed by user ID.
	 *
	 * @var array<int,string|null>
	 */
	private $user_group_cache = array();

	/**
	 * Cached group names keyed by user ID.
	 *
	 * @var array<int,string|null>
	 */
	private $user_group_name_cache = array();

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_User_Group_Repository
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize repository dependencies.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function __construct() {
		$this->db = WCCG_Database::instance();
	}

	/**
	 * Get the group ID assigned to a user.
	 *
	 * @since  1.0.0
	 * @param  int $user_id WordPress user ID.
	 * @return string|null Group ID as a string, or null if the user has no group assignment.
	 */
	public function get_user_group( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( array_key_exists( $user_id, $this->user_group_cache ) ) {
			return $this->user_group_cache[ $user_id ];
		}

		$user_groups_table = $this->db->get_table_name( 'user_groups' );

		$this->user_group_cache[ $user_id ] = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be parameterized; placeholders are used for dynamic values.
				'SELECT group_id FROM ' . $user_groups_table . ' WHERE user_id = %d',
				$user_id
			)
		);

		return $this->user_group_cache[ $user_id ];
	}

	/**
	 * Get the group name for a user.
	 *
	 * @since  1.0.0
	 * @param  int $user_id WordPress user ID.
	 * @return string|null Group name, or null if the user has no group assignment.
	 */
	public function get_user_group_name( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( array_key_exists( $user_id, $this->user_group_name_cache ) ) {
			return $this->user_group_name_cache[ $user_id ];
		}

		$groups_table      = $this->db->get_table_name( 'groups' );
		$user_groups_table = $this->db->get_table_name( 'user_groups' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$this->user_group_name_cache[ $user_id ] = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT g.group_name
            FROM ' . $groups_table . ' g
            JOIN ' . $user_groups_table . ' ug ON g.group_id = ug.group_id
            WHERE ug.user_id = %d',
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return $this->user_group_name_cache[ $user_id ];
	}

	/**
	 * Get all user IDs assigned to a group.
	 *
	 * @since  1.0.0
	 * @param  int $group_id Customer group ID.
	 * @return array Array of user ID strings.
	 */
	public function get_users_in_group( $group_id ) {
		global $wpdb;

		$user_groups_table = $this->db->get_table_name( 'user_groups' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_col(
			$wpdb->prepare(
				'SELECT user_id
            FROM ' . $user_groups_table . '
            WHERE group_id = %d',
				$group_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Fetch all customer groups ordered by name.
	 *
	 * @since  1.0.0
	 * @return object[] Array of stdClass rows from the groups table.
	 */
	public function get_groups() {
		global $wpdb;

		$groups_table = $this->db->get_table_name( 'groups' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be parameterized; this trusted plugin table name is not user input.
		return $wpdb->get_results( 'SELECT * FROM ' . $groups_table . ' ORDER BY group_name ASC' );
	}

	/**
	 * Assign multiple users to a group, replacing any existing assignments in batches of 1000.
	 *
	 * @since  1.0.0
	 * @param  int[] $user_ids Array of WordPress user IDs.
	 * @param  int   $group_id Target customer group ID.
	 * @return bool True on success, false if any batch fails or inputs are empty.
	 */
	public function bulk_assign_user_groups( $user_ids, $group_id ) {
		if ( empty( $user_ids ) || ! $group_id ) {
			return false;
		}

		return $this->db->transaction(
			function () use ( $user_ids, $group_id ) {
				foreach ( array_chunk( $user_ids, 1000 ) as $batch_user_ids ) {
					$result = $this->replace_batch_assignments( $batch_user_ids, $group_id );
					if ( $result === false ) {
						throw new Exception( 'Batch assignment failed' );
					}
				}

				return true;
			}
		);
	}

	/**
	 * Remove group assignments for the provided users.
	 *
	 * @since  1.0.0
	 * @param  int[] $user_ids Array of WordPress user IDs.
	 * @return int|false Number of deleted rows, or false on failure.
	 */
	public function bulk_unassign_user_groups( $user_ids ) {
		if ( empty( $user_ids ) ) {
			return 0;
		}

		return $this->db->transaction(
			function () use ( $user_ids ) {
				global $wpdb;

				$rows_deleted = 0;
				foreach ( $user_ids as $user_id ) {
					$delete_result = $wpdb->delete( $this->db->get_table_name( 'user_groups' ), array( 'user_id' => $user_id ), array( '%d' ) );
					if ( $delete_result === false ) {
						return false;
					}

					$rows_deleted += (int) $delete_result;
				}

				return $rows_deleted;
			}
		);
	}

	/**
	 * Remove user-group assignments whose group no longer exists in the groups table.
	 *
	 * @since  1.0.0
	 * @return bool True on success, false on transaction failure.
	 */
	public function cleanup_orphaned_group_assignments() {
		global $wpdb;

		return $this->db->transaction(
			function () use ( $wpdb ) {
				$user_groups_table = $this->db->get_table_name( 'user_groups' );
				$groups_table      = $this->db->get_table_name( 'groups' );

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
				$result = $wpdb->query(
					'DELETE ug
                FROM ' . $user_groups_table . ' ug
                LEFT JOIN ' . $groups_table . ' g ON ug.group_id = g.group_id
                WHERE g.group_id IS NULL'
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

				return $result !== false;
			}
		);
	}

	/**
	 * Replace the assignments for a batch of users with a single target group.
	 *
	 * @since  1.0.0
	 * @param  int[] $batch_user_ids WordPress user IDs in the current batch.
	 * @param  int   $group_id       Target customer group ID.
	 * @return int|false Number of assignments written, or false on failure.
	 */
	private function replace_batch_assignments( $batch_user_ids, $group_id ) {
		global $wpdb;

		$user_groups_table = $this->db->get_table_name( 'user_groups' );
		$batch_user_ids    = array_values( array_unique( array_filter( array_map( 'absint', $batch_user_ids ) ) ) );
		$group_id          = absint( $group_id );

		foreach ( $batch_user_ids as $user_id ) {
			$delete_result = $wpdb->delete( $user_groups_table, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( false === $delete_result ) {
				return false;
			}
		}

		foreach ( $batch_user_ids as $user_id ) {
			$insert_result = $wpdb->insert(
				$user_groups_table,
				array(
					'user_id'  => $user_id,
					'group_id' => $group_id,
				),
				array( '%d', '%d' )
			);
			if ( false === $insert_result ) {
				return false;
			}
		}

		return count( $batch_user_ids );
	}
}
