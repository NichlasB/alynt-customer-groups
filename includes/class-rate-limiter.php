<?php
/**
 * Request rate limiting using WordPress transients.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracks per-user request counts in transients and rejects requests that exceed policy limits.
 *
 * Policies:
 * - price_calc:   100 requests / 1 minute
 * - group_change:  10 requests / 5 minutes
 * - default:       50 requests / 1 minute
 *
 * Super admins are always exempt from rate limiting.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Rate_Limiter {
    private static $instance = null;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Rate_Limiter
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Check whether a user has exceeded the rate limit for an action and increment the counter.
     *
     * @since  1.0.0
     * @param  int    $user_id WordPress user ID.
     * @param  string $action  Rate-limit bucket. One of 'price_calc', 'group_change', or any string.
     * @return bool True if the request is within limits (allowed), false if the limit is exceeded.
     */
    public function check_rate_limit($user_id, $action = 'price_calc') {
        if (is_super_admin()) {
            return true;
        }

        $policy = $this->get_policy($action);
        $transient_key = 'wccg_rate_limit_' . $action . '_' . $user_id;
        $limit_data = get_transient($transient_key);

        if (false === $limit_data || (time() - $limit_data['first_request']) > $policy['time_window']) {
            set_transient(
                $transient_key,
                array(
                    'count'         => 1,
                    'first_request' => time()
                ),
                $policy['time_window']
            );
            return true;
        }

        if ($limit_data['count'] >= $policy['max_requests']) {
            WCCG_Logger::instance()->log_error(
                'Rate limit exceeded',
                array(
                    'user_id'    => $user_id,
                    'action'     => $action,
                    'limit_data' => $limit_data
                )
            );
            return false;
        }

        $limit_data['count']++;
        set_transient($transient_key, $limit_data, $policy['time_window']);

        return true;
    }

    private function get_policy($action) {
        switch ($action) {
            case 'price_calc':
                return array(
                    'max_requests' => 100,
                    'time_window'  => MINUTE_IN_SECONDS
                );
            case 'group_change':
                return array(
                    'max_requests' => 10,
                    'time_window'  => MINUTE_IN_SECONDS * 5
                );
            default:
                return array(
                    'max_requests' => 50,
                    'time_window'  => MINUTE_IN_SECONDS
                );
        }
    }
}
