<?php
/**
 * Handles plugin activation: creates database tables and schedules cron events.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates required database tables, adds schema columns for upgrades, and schedules cron tasks.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Activator {

	/**
	 * Run all activation routines: create tables, migrate schema, schedule tasks, and record version.
	 *
	 * @since  1.0.0
	 * @param  bool $network_wide Whether activation is running network-wide on multisite.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		try {
			if ( is_multisite() && $network_wide ) {
				$site_ids = get_sites(
					array(
						'fields' => 'ids',
					)
				);

				foreach ( $site_ids as $site_id ) {
					switch_to_blog( (int) $site_id );
					try {
						self::run_activation_for_current_site();
					} finally {
						restore_current_blog();
					}
				}
			} else {
				self::run_activation_for_current_site();
			}
		} catch ( Exception $e ) {
			error_log( 'WCCG Critical: Activation failed - ' . $e->getMessage() );
		}
	}

	/**
	 * Ensure the database schema is synced for the current plugin code.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public static function maybe_sync_schema() {
		$schema_version = get_option( 'wccg_schema_version', '' );
		if ( $schema_version === WCCG_VERSION ) {
			return;
		}

		try {
			self::create_tables();
			self::backfill_pricing_rules_data();
			update_option( 'wccg_schema_version', WCCG_VERSION );
		} catch ( Exception $e ) {
			error_log( 'WCCG Critical: Schema sync failed - ' . $e->getMessage() );
		}
	}

	/**
	 * Run activation routines for the currently selected site.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	private static function run_activation_for_current_site() {
		self::create_tables();
		self::backfill_pricing_rules_data();
		self::schedule_tasks();
		self::set_version();
	}

	/**
	 * Create all plugin database tables using dbDelta.
	 *
	 * @since  1.0.0
	 * @return void
	 * @throws Exception If any critical table fails to be created.
	 */
	private static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$error_log_table       = $wpdb->prefix . 'wccg_error_log';
		$groups_table          = $wpdb->prefix . 'customer_groups';
		$user_groups_table     = $wpdb->prefix . 'user_groups';
		$pricing_rules_table   = $wpdb->prefix . 'pricing_rules';
		$rule_products_table   = $wpdb->prefix . 'rule_products';
		$rule_categories_table = $wpdb->prefix . 'rule_categories';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$critical_tables = array(
			$groups_table,
			$user_groups_table,
			$pricing_rules_table,
			$rule_products_table,
			$rule_categories_table,
		);

		$tables_sql = array(
			$error_log_table       => "CREATE TABLE IF NOT EXISTS $error_log_table (
                log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                timestamp DATETIME NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                message TEXT NOT NULL,
                data TEXT,
                severity VARCHAR(20) NOT NULL,
                PRIMARY KEY (log_id),
                KEY timestamp (timestamp),
                KEY severity (severity)
            ) $charset_collate;",

			$groups_table          => "CREATE TABLE IF NOT EXISTS $groups_table (
                group_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                group_name VARCHAR(255) NOT NULL,
                group_description TEXT,
                PRIMARY KEY (group_id)
            ) $charset_collate;",

			$user_groups_table     => "CREATE TABLE IF NOT EXISTS $user_groups_table (
                user_id BIGINT(20) UNSIGNED NOT NULL,
                group_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (user_id),
                KEY group_id (group_id)
            ) $charset_collate;",

			$pricing_rules_table   => "CREATE TABLE IF NOT EXISTS $pricing_rules_table (
                rule_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                group_id INT UNSIGNED NOT NULL,
                discount_type ENUM('percentage','fixed') NOT NULL,
                discount_value DECIMAL(10,2) NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                sort_order INT UNSIGNED DEFAULT 0,
                start_date DATETIME NULL,
                end_date DATETIME NULL,
                PRIMARY KEY (rule_id),
                KEY group_id (group_id),
                KEY created_at (created_at),
                KEY is_active (is_active),
                KEY sort_order (sort_order),
                KEY start_date (start_date),
                KEY end_date (end_date),
                KEY group_active_window (group_id, is_active, start_date, end_date),
                KEY active_end_date (is_active, end_date),
                KEY active_start_end (is_active, start_date, end_date)
            ) $charset_collate;",

			$rule_products_table   => "CREATE TABLE IF NOT EXISTS $rule_products_table (
                rule_id INT UNSIGNED NOT NULL,
                product_id BIGINT(20) UNSIGNED NOT NULL,
                PRIMARY KEY (rule_id, product_id),
                KEY product_id (product_id)
            ) $charset_collate;",

			$rule_categories_table => "CREATE TABLE IF NOT EXISTS $rule_categories_table (
                rule_id INT UNSIGNED NOT NULL,
                category_id BIGINT(20) UNSIGNED NOT NULL,
                PRIMARY KEY (rule_id, category_id),
                KEY category_id (category_id)
            ) $charset_collate;",
		);

		$failed_tables = array();
		foreach ( $tables_sql as $table => $sql ) {
			dbDelta( $sql );

			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;

			if ( ! $table_exists && in_array( $table, $critical_tables, true ) ) {
				$failed_tables[] = $table;
			}
		}

		if ( ! empty( $failed_tables ) ) {
			error_log( 'WCCG Critical: Failed to create critical tables: ' . implode( ', ', $failed_tables ) );
			throw new Exception( 'Failed to create critical database tables' );
		}
	}


	/**
	 * Backfill NULL and default values for pricing_rules columns created during schema sync.
	 *
	 * @since  1.0.0
	 * @return void
	 * @throws Exception If the UPDATE statement fails.
	 */
	private static function backfill_pricing_rules_data() {
		global $wpdb;
		$pricing_rules_table = $wpdb->prefix . 'pricing_rules';
		$result              = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be parameterized; this update uses a trusted prefixed table name and no user input.
			'UPDATE ' . $pricing_rules_table . '
            SET created_at = COALESCE(created_at, CURRENT_TIMESTAMP),
                is_active = COALESCE(is_active, 1),
                sort_order = CASE WHEN sort_order IS NULL OR sort_order = 0 THEN rule_id ELSE sort_order END'
		);

		if ( $result === false ) {
			error_log( 'WCCG Critical: Failed to migrate rules - ' . $wpdb->last_error );
			throw new Exception( 'Failed to migrate existing rules' );
		}
	}

	/**
	 * Schedule the daily cleanup and 5-minute expiration-check cron events.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function schedule_tasks() {
		if ( ! wp_next_scheduled( 'wccg_cleanup_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'wccg_cleanup_cron' );
		}

		if ( ! wp_next_scheduled( 'wccg_check_expired_rules' ) ) {
			wp_schedule_event( time(), 'wccg_five_minutes', 'wccg_check_expired_rules' );
		}
	}

	/**
	 * Record the current plugin version and installation date in WordPress options.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function set_version() {
		update_option( 'wccg_version', WCCG_VERSION );
		update_option( 'wccg_schema_version', WCCG_VERSION );
		update_option( 'wccg_installation_date', current_time( 'mysql' ) );
	}
}
