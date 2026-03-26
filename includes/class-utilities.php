<?php
/**
 * Utility facade — aggregates security, sanitization, rate limiting, and logging helpers.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a single access point for the plugin's cross-cutting concerns.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Utilities {
	private static $instance = null;

	private $security;
	private $input;
	private $rate_limiter;
	private $logger;

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Utilities
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->security     = WCCG_Security_Helper::instance();
		$this->input        = WCCG_Input_Sanitizer::instance();
		$this->rate_limiter = WCCG_Rate_Limiter::instance();
		$this->logger       = WCCG_Logger::instance();
	}

	/**
	 * Verify a WordPress nonce from the current request.
	 *
	 * @since  1.0.0
	 * @param  string $nonce_name The nonce action name to verify against.
	 * @return bool True on success; calls wp_die() on failure.
	 */
	public function verify_nonce( $nonce_name ) {
		return $this->security->verify_nonce( $nonce_name );
	}

	/**
	 * Escape a value for safe output.
	 *
	 * @since  1.0.0
	 * @param  mixed  $data The value to escape.
	 * @param  string $type Escape context: 'text' (default), 'html', 'url', 'attr', 'textarea'.
	 * @return string Escaped string.
	 */
	public function escape_output( $data, $type = 'text' ) {
		return $this->security->escape_output( $data, $type );
	}

	/**
	 * Verify the current user has the manage_woocommerce capability; die on failure.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function verify_admin_access() {
		$this->security->verify_admin_access();
	}

	/**
	 * Validate a discount type and value combination.
	 *
	 * @since  1.0.0
	 * @param  string $discount_type  'percentage' or 'fixed'.
	 * @param  mixed  $discount_value The discount amount to validate.
	 * @return array { @type bool $valid, @type string $message (only present on failure) }
	 */
	public function validate_pricing_input( $discount_type, $discount_value ) {
		return $this->input->validate_pricing_input( $discount_type, $discount_value );
	}

	/**
	 * Sanitize an input value according to the specified type.
	 *
	 * @since  1.0.0
	 * @param  mixed  $data The raw input value.
	 * @param  string $type Sanitization type: 'text', 'int', 'float', 'price', 'email',
	 *                      'url', 'textarea', 'array', 'group_id', 'discount_type'.
	 * @param  array  $args Optional additional arguments for the sanitizer.
	 * @return mixed Sanitized value.
	 */
	public function sanitize_input( $data, $type = 'text', $args = array() ) {
		return $this->input->sanitize_input( $data, $type, $args );
	}

	/**
	 * Check whether the current request exceeds the rate limit for an action.
	 *
	 * @since  1.0.0
	 * @param  int    $user_id WordPress user ID.
	 * @param  string $action  Rate-limit bucket: 'price_calc' (100/min), 'group_change' (10/5min).
	 * @return bool True if the request is within limits, false if the limit is exceeded.
	 */
	public function check_rate_limit( $user_id, $action = 'price_calc' ) {
		return $this->rate_limiter->check_rate_limit( $user_id, $action );
	}

	/**
	 * Log an error or informational message to the database and PHP error log.
	 *
	 * @since  1.0.0
	 * @param  string $message  Human-readable description of the event.
	 * @param  array  $data     Optional structured data to attach to the log entry.
	 * @param  string $severity One of 'debug', 'info', 'warning', 'error', 'critical'.
	 * @return bool True if the entry was inserted, false otherwise.
	 */
	public function log_error( $message, $data = array(), $severity = 'error' ) {
		return $this->logger->log_error( $message, $data, $severity );
	}

	/**
	 * Return the total number of entries in the error log table.
	 *
	 * @since  1.0.0
	 * @return int Log entry count, or 0 if the table does not exist.
	 */
	public function get_log_count() {
		return $this->logger->get_log_count();
	}
}
