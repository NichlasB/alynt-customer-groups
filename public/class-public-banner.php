<?php
/**
 * Sticky pricing group banner for the frontend.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a sticky notification banner informing the logged-in customer of their active pricing group.
 *
 * Outputs at wp_body_open with a wp_footer fallback for themes that do not call wp_body_open.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Public_Banner {
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Public_Banner|null
	 */
	private static $instance = null;

	/**
	 * Database facade.
	 *
	 * @var WCCG_Database
	 */
	private $db;

	/**
	 * Shared utility helper.
	 *
	 * @var WCCG_Utilities
	 */
	private $utils;

	/**
	 * Tracks whether the banner already rendered this request.
	 *
	 * @var bool
	 */
	private $banner_displayed = false;

	/**
	 * Cache of active-rule checks by group ID.
	 *
	 * @var array<int,bool>
	 */
	private $active_group_rule_cache = array();

	/**
	 * Cache of resolved banner titles by user ID.
	 *
	 * @var array<int|null|string>
	 */
	private $display_title_cache = array();

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Public_Banner
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize banner dependencies.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function __construct() {
		$this->db    = WCCG_Database::instance();
		$this->utils = WCCG_Utilities::instance();
	}

	/**
	 * Build a translated banner message for the given context.
	 *
	 * @since  1.0.0
	 * @param  string $message_key Message template key.
	 * @param  mixed  ...$args     Replacement values for the selected template.
	 * @return string
	 */
	private function get_banner_message( $message_key, ...$args ) {
		$messages = array(
			'assigned_group' =>
				/* translators: 1: customer first name, 2: pricing group name wrapped in strong tags. */
				__( '%1$s, you receive %2$s pricing on eligible products!', 'alynt-customer-groups' ),
			'default_group'  =>
				/* translators: %s: pricing group name wrapped in strong tags. */
				__( 'Enjoy %s pricing on eligible products!', 'alynt-customer-groups' ),
		);

		return vsprintf( $messages[ $message_key ], $args );
	}

	/**
	 * Resolve the display title for a user's pricing group, checking the default group as fallback.
	 *
	 * Only returns a title when the group has at least one active, in-window pricing rule,
	 * so the banner is suppressed for groups with no applicable rules.
	 *
	 * @since  1.1.0
	 * @param  int|null $user_id WordPress user ID, or null to use the current user.
	 * @return string|null Group display title (custom title or group name), or null if none applies.
	 */
	public function get_pricing_group_display_title( $user_id = null ) {
		global $wpdb;

		if ( $user_id === null ) {
			$user_id = get_current_user_id();
		}

		$cache_key = absint( $user_id );
		if ( array_key_exists( $cache_key, $this->display_title_cache ) ) {
			return $this->display_title_cache[ $cache_key ];
		}

		if ( $user_id > 0 ) {
			$group_name = $this->db->get_user_group_name( $user_id );
			if ( $group_name ) {
				$this->display_title_cache[ $cache_key ] = $group_name;
				return $this->display_title_cache[ $cache_key ];
			}
		}

		$default_group_id = get_option( 'wccg_default_group_id', 0 );
		if ( ! $default_group_id ) {
			$this->display_title_cache[ $cache_key ] = null;
			return $this->display_title_cache[ $cache_key ];
		}

		if ( ! $this->group_has_active_rule( $default_group_id ) ) {
			$this->display_title_cache[ $cache_key ] = null;
			return $this->display_title_cache[ $cache_key ];
		}

		$custom_title = get_option( 'wccg_default_group_custom_title', '' );
		if ( empty( $custom_title ) ) {
			$custom_title = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT group_name FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
					$default_group_id
				)
			);
		}

		$this->display_title_cache[ $cache_key ] = ! empty( $custom_title ) ? $custom_title : null;

		return $this->display_title_cache[ $cache_key ];
	}

	/**
	 * Render the sticky banner at wp_body_open and mark it as displayed.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function display_sticky_banner() {
		if ( did_action( 'wp_body_open' ) ) {
			$this->banner_displayed = true;
		}

		$this->render_sticky_banner();
	}

	/**
	 * Render the sticky banner at wp_footer if it was not already output at wp_body_open.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function display_sticky_banner_fallback() {
		if ( empty( $this->banner_displayed ) ) {
			$this->render_sticky_banner();
		}
	}

	/**
	 * Render the pricing banner markup when a qualifying group is active.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function render_sticky_banner() {
		$user_id  = get_current_user_id();
		$group_id = $user_id ? $this->db->get_user_group( $user_id ) : null;
		if ( $group_id ) {
			$user_info  = get_userdata( $user_id );
			$first_name = $user_info->first_name ? $user_info->first_name : $user_info->display_name;
			$group_name = $this->db->get_user_group_name( $user_id );
			$has_rule   = $this->group_has_active_rule( $group_id );

			if ( $has_rule && $group_name ) {
				echo '<div class="wccg-sticky-banner">' . wp_kses_post(
					$this->get_banner_message(
						'assigned_group',
						$this->utils->escape_output( $first_name ),
						'<strong>' . $this->utils->escape_output( $group_name ) . '</strong>'
					)
				) . '</div>';
			}
			return;
		}

		$custom_title = $this->get_pricing_group_display_title( $user_id );
		if ( $custom_title ) {
			echo '<div class="wccg-sticky-banner">' . wp_kses_post(
				$this->get_banner_message(
					'default_group',
					'<strong>' . $this->utils->escape_output( $custom_title ) . '</strong>'
				)
			) . '</div>';
		}
	}

	/**
	 * Check whether a group has any active pricing rule in the current time window.
	 *
	 * @since  1.0.0
	 * @param  int $group_id Customer group ID.
	 * @return bool
	 */
	private function group_has_active_rule( $group_id ) {
		global $wpdb;

		$group_id = absint( $group_id );
		if ( isset( $this->active_group_rule_cache[ $group_id ] ) ) {
			return $this->active_group_rule_cache[ $group_id ];
		}

		$this->active_group_rule_cache[ $group_id ] = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}pricing_rules
            WHERE group_id = %d AND is_active = 1
            AND (start_date IS NULL OR start_date <= UTC_TIMESTAMP())
            AND (end_date IS NULL OR end_date >= UTC_TIMESTAMP())
            LIMIT 1",
				$group_id
			)
		);

		return $this->active_group_rule_cache[ $group_id ];
	}
}
