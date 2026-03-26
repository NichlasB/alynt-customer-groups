<?php
/**
 * Error and event logging to the plugin's database log table.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes log entries to {prefix}wccg_error_log and (for critical/debug-log-enabled entries)
 * to the PHP error log.
 *
 * Only entries at 'error' severity or higher are stored. Entries below that threshold are
 * silently ignored unless WP_DEBUG_LOG is enabled.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Logger {
	private static $instance = null;

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Logger
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Log a message to the database (and optionally the PHP error log).
	 *
	 * Messages below 'error' severity are dropped unless WP_DEBUG_LOG is enabled.
	 * Critical messages are always written to the PHP error log.
	 *
	 * @since  1.0.0
	 * @param  string $message  Human-readable description of the event.
	 * @param  array  $data     Optional structured data to store as JSON.
	 * @param  string $severity Severity level: 'debug', 'info', 'warning', 'error', 'critical'.
	 * @return bool True if the entry was inserted successfully, false otherwise.
	 */
	public function log_error( $message, $data = array(), $severity = 'error' ) {
		$severity_levels = array(
			'debug'    => 0,
			'info'     => 1,
			'warning'  => 2,
			'error'    => 3,
			'critical' => 4,
		);

		if ( ! isset( $severity_levels[ $severity ] ) || $severity_levels[ $severity ] < $severity_levels['error'] ) {
			return false;
		}

		$current_user_id = get_current_user_id();
		$timestamp       = current_time( 'mysql' );

		if ( $severity === 'critical' || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			$log_entry = sprintf(
				'[%s] [%s] [User: %d] %s',
				$timestamp,
				strtoupper( $severity ),
				$current_user_id,
				$message
			);

			if ( ! empty( $data ) ) {
				$json_data = wp_json_encode( $data );
				if ( $json_data !== false ) {
					$log_entry .= ' | Data: ' . $json_data;
				}
			}

			error_log( $log_entry );
		}

		try {
			global $wpdb;

			$table_name   = $wpdb->prefix . 'wccg_error_log';
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name;

			if ( $table_exists ) {
				$json_data = ! empty( $data ) ? wp_json_encode( $data ) : null;
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					$json_data = wp_json_encode( array( 'error' => 'Data not JSON encodable' ) );
				}

				$result = $wpdb->insert(
					$table_name,
					array(
						'timestamp' => $timestamp,
						'user_id'   => $current_user_id,
						'message'   => $message,
						'data'      => $json_data,
						'severity'  => $severity,
					),
					array( '%s', '%d', '%s', '%s', '%s' )
				);

				return $result !== false;
			}

			return false;
		} catch ( Exception $e ) {
			error_log( 'WCCG Error Logger Failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Return the total number of entries currently in the error log table.
	 *
	 * @since  1.0.0
	 * @return int Entry count, or 0 if the table does not exist.
	 */
	public function get_log_count() {
		global $wpdb;

		$table_name   = $wpdb->prefix . 'wccg_error_log';
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;

		if ( $table_exists ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
		}

		return 0;
	}
}
